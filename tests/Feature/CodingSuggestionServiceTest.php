<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LlmRequest;
use App\Services\Coding\CodingSuggestionService;
use App\Services\Coding\Exceptions\MalformedLlmResponseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

final class CodingSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_NOTE = <<<'NOTE'
    CHIEF COMPLAINT: Left wrist pain after fall from bicycle yesterday.
    EXAM: Focal tenderness over distal radius, limited ROM, no neurovascular
    deficit. Xray obtained — nondisplaced distal radius fracture.
    PLAN: Short-arm cast applied. Follow up in 2 weeks.
    NOTE;

    private const VALID_PAYLOAD = [
        'encounter_summary' => 'Adult presenting with left distal radius fracture after mechanical fall. Short-arm cast applied; follow-up scheduled.',
        'cpt_codes'         => [
            [
                'code'        => '25600',
                'description' => 'Closed treatment of distal radial fracture; without manipulation',
                'rationale'   => 'Nondisplaced distal radius fracture treated with short-arm cast, no manipulation performed.',
                'modifiers'   => ['LT'],
            ],
        ],
        'icd10_codes' => [
            [
                'code'        => 'S52.501A',
                'description' => 'Unspecified fracture of lower end of left radius, initial encounter',
                'rationale'   => 'Documented nondisplaced distal radius fracture, initial encounter for closed fracture.',
            ],
        ],
        'confidence' => 'high',
    ];

    public function test_happy_path_returns_decoded_payload_and_logs_one_audit_row(): void
    {
        $primary = (new FakeLlmClient('anthropic'))->queueResponse(self::VALID_PAYLOAD);

        $service = new CodingSuggestionService(
            primary:       $primary,
            fallback:      null,
            primaryModel:  'claude-opus-4-7',
            fallbackModel: null,
            maxRetries:    2,
        );

        $result = $service->suggestCodes(self::SAMPLE_NOTE, encounterId: 'enc_test_1');

        $this->assertSame('anthropic', $result->provider);
        $this->assertSame('claude-opus-4-7', $result->model);
        $this->assertSame(1, $result->attemptsMade);
        $this->assertSame('25600', $result->payload['cpt_codes'][0]['code']);

        $this->assertSame(1, LlmRequest::count());
        $this->assertSame('ok', LlmRequest::first()->status);

        $primary->assertSequenceConsumed();
    }

    public function test_malformed_json_triggers_retry_with_correction_and_eventually_succeeds(): void
    {
        $primary = (new FakeLlmClient('anthropic'))
            ->queueResponse('this is not json at all, it is prose')
            ->queueResponse(self::VALID_PAYLOAD);

        $service = new CodingSuggestionService(
            primary:       $primary,
            fallback:      null,
            primaryModel:  'claude-opus-4-7',
            fallbackModel: null,
            maxRetries:    2,
        );

        $result = $service->suggestCodes(self::SAMPLE_NOTE, encounterId: 'enc_test_2');

        $this->assertSame(2, $result->attemptsMade);
        $this->assertSame('25600', $result->payload['cpt_codes'][0]['code']);

        // Audit: one failed attempt + one successful attempt = 2 rows.
        $rows = LlmRequest::orderBy('attempt')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('schema_invalid', $rows[0]->status);
        $this->assertSame('ok',             $rows[1]->status);

        // The retry call MUST have used the corrective system prompt (which
        // contains the malformed output verbatim).
        $this->assertStringContainsString(
            'this is not json at all',
            $primary->calls[1]['systemPrompt'],
            'Retry must include the malformed response in the corrective prompt',
        );

        $primary->assertSequenceConsumed();
    }

    public function test_schema_violation_triggers_retry_even_if_json_decodes(): void
    {
        // Valid JSON, but missing required top-level `confidence` field.
        $invalidButDecodable = [
            'encounter_summary' => 'short summary here for the encounter in question',
            'cpt_codes'         => [],
            'icd10_codes'       => [],
            // confidence missing — schema violation
        ];

        $primary = (new FakeLlmClient('anthropic'))
            ->queueResponse($invalidButDecodable)
            ->queueResponse(self::VALID_PAYLOAD);

        $service = new CodingSuggestionService(
            primary:       $primary,
            fallback:      null,
            primaryModel:  'claude-opus-4-7',
            fallbackModel: null,
            maxRetries:    2,
        );

        $result = $service->suggestCodes(self::SAMPLE_NOTE);

        $this->assertSame(2, $result->attemptsMade);
        $this->assertSame('ok', LlmRequest::orderBy('attempt', 'desc')->first()->status);
    }

    public function test_all_primary_retries_fail_falls_back_to_secondary_provider(): void
    {
        $primary = (new FakeLlmClient('anthropic'))
            ->queueResponse('malformed #1')
            ->queueResponse('malformed #2')
            ->queueResponse('malformed #3');

        $fallback = (new FakeLlmClient('openai'))
            ->queueResponse(self::VALID_PAYLOAD);

        $service = new CodingSuggestionService(
            primary:       $primary,
            fallback:      $fallback,
            primaryModel:  'claude-opus-4-7',
            fallbackModel: 'gpt-4o',
            maxRetries:    2,
        );

        $result = $service->suggestCodes(self::SAMPLE_NOTE, encounterId: 'enc_test_4');

        $this->assertSame('openai', $result->provider);
        $this->assertSame(4,        $result->attemptsMade); // 3 primary + 1 fallback

        $this->assertSame(
            'fallback_used',
            LlmRequest::orderBy('attempt', 'desc')->first()->status,
        );

        $primary->assertSequenceConsumed();
        $fallback->assertSequenceConsumed();
    }

    public function test_primary_transport_error_skips_remaining_primary_retries_and_uses_fallback(): void
    {
        $primary  = (new FakeLlmClient('anthropic'))->queueTransportError();
        $fallback = (new FakeLlmClient('openai'))->queueResponse(self::VALID_PAYLOAD);

        $service = new CodingSuggestionService(
            primary:       $primary,
            fallback:      $fallback,
            primaryModel:  'claude-opus-4-7',
            fallbackModel: 'gpt-4o',
            maxRetries:    2,
        );

        $result = $service->suggestCodes(self::SAMPLE_NOTE, encounterId: 'enc_test_5');

        $this->assertSame('openai', $result->provider);
        $this->assertSame(1, $primary->callCount(), 'Transport error must skip remaining primary retries');
    }

    public function test_both_providers_fail_throws_malformed_exception(): void
    {
        $primary = (new FakeLlmClient('anthropic'))
            ->queueResponse('bad 1')
            ->queueResponse('bad 2')
            ->queueResponse('bad 3');

        $fallback = (new FakeLlmClient('openai'))->queueResponse('bad 4');

        $service = new CodingSuggestionService(
            primary:       $primary,
            fallback:      $fallback,
            primaryModel:  'claude-opus-4-7',
            fallbackModel: 'gpt-4o',
            maxRetries:    2,
        );

        $this->expectException(MalformedLlmResponseException::class);

        try {
            $service->suggestCodes(self::SAMPLE_NOTE);
        } finally {
            // Audit rows MUST still be written even on total failure.
            $this->assertSame(4, LlmRequest::count());
        }
    }

    public function test_code_fence_wrapped_response_is_still_decoded(): void
    {
        $wrapped = "```json\n" . json_encode(self::VALID_PAYLOAD) . "\n```";

        $primary = (new FakeLlmClient('anthropic'))->queueResponse($wrapped);

        $service = new CodingSuggestionService(
            primary:       $primary,
            fallback:      null,
            primaryModel:  'claude-opus-4-7',
            fallbackModel: null,
            maxRetries:    2,
        );

        $result = $service->suggestCodes(self::SAMPLE_NOTE);
        $this->assertSame(1, $result->attemptsMade);
        $this->assertSame('25600', $result->payload['cpt_codes'][0]['code']);
    }
}
