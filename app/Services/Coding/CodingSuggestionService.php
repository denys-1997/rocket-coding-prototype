<?php

declare(strict_types=1);

namespace App\Services\Coding;

use App\Models\LlmRequest;
use App\Services\Anthropic\Contracts\LlmClient;
use App\Services\Coding\DTOs\CodingSuggestionResult;
use App\Services\Coding\DTOs\LlmResponse;
use App\Services\Coding\Exceptions\LlmTransportException;
use App\Services\Coding\Exceptions\MalformedLlmResponseException;
use App\Services\Coding\Prompts\CodingSystemPrompt;
use App\Services\Coding\Schemas\CodingResponseSchema;
use Illuminate\Support\Facades\Log;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Ramsey\Uuid\Uuid;

/**
 * Orchestrates a coding-suggestion request end-to-end.
 *
 * Flow:
 *   1. Build system prompt (schema-embedded).
 *   2. Call primary LLM (Claude).
 *   3. Decode + validate response against CodingResponseSchema.
 *   4. On schema / decode failure → retry with a corrective prompt up to
 *      LLM_MAX_RETRIES. Each retry tells the model exactly what was wrong.
 *   5. On transport failure OR exhausted retries → fall back to secondary
 *      provider (OpenAI).
 *   6. If both providers fail, throw MalformedLlmResponseException. The
 *      caller (queue job) routes the job to a dead-letter queue for human
 *      review.
 *
 * Every attempt — success, schema-invalid, or transport-error — is persisted
 * to llm_requests for audit. The audit row is immutable.
 */
final class CodingSuggestionService
{
    public function __construct(
        private readonly LlmClient $primary,
        private readonly ?LlmClient $fallback,
        private readonly string $primaryModel,
        private readonly ?string $fallbackModel,
        private readonly int $maxRetries = 2,
    ) {
    }

    public function suggestCodes(
        string $clinicalNote,
        ?string $encounterId = null,
    ): CodingSuggestionResult {
        $requestId = Uuid::uuid4()->toString();

        $attempts = 0;
        $totalInputTokens  = 0;
        $totalOutputTokens = 0;
        $totalCost         = 0.0;

        $lastMalformed = null;
        $lastErrors    = [];

        // --- Primary provider with retry-on-schema-failure ---
        for ($i = 0; $i <= $this->maxRetries; $i++) {
            $attempts++;

            $systemPrompt = $lastMalformed === null
                ? CodingSystemPrompt::base()
                : CodingSystemPrompt::correction($lastMalformed, $lastErrors);

            try {
                $response = $this->primary->sendMessage(
                    systemPrompt: $systemPrompt,
                    messages:     [['role' => 'user', 'content' => $clinicalNote]],
                    model:        $this->primaryModel,
                );
            } catch (LlmTransportException $e) {
                $this->logAttempt(
                    requestId:    $requestId,
                    encounterId:  $encounterId,
                    provider:     $this->primary->provider(),
                    model:        $this->primaryModel,
                    status:       'transport_error',
                    attempt:      $attempts,
                    systemPrompt: $systemPrompt,
                    clinicalNote: $clinicalNote,
                    response:     null,
                    errors:       [$e->getMessage()],
                );

                // Transport error on primary → skip remaining retries, go to fallback.
                break;
            }

            $totalInputTokens  += $response->inputTokens;
            $totalOutputTokens += $response->outputTokens;
            $totalCost         += $response->costUsd;

            [$decoded, $errors] = $this->decodeAndValidate($response->content);

            if ($decoded !== null) {
                $this->logAttempt(
                    requestId:    $requestId,
                    encounterId:  $encounterId,
                    provider:     $response->provider,
                    model:        $response->model,
                    status:       'ok',
                    attempt:      $attempts,
                    systemPrompt: $systemPrompt,
                    clinicalNote: $clinicalNote,
                    response:     $response,
                    errors:       null,
                );

                return new CodingSuggestionResult(
                    requestId:         $requestId,
                    provider:          $response->provider,
                    model:             $response->model,
                    payload:           $decoded,
                    attemptsMade:      $attempts,
                    totalInputTokens:  $totalInputTokens,
                    totalOutputTokens: $totalOutputTokens,
                    totalCostUsd:      $totalCost,
                );
            }

            // Schema-invalid or decode-failed — log and retry with correction.
            $this->logAttempt(
                requestId:    $requestId,
                encounterId:  $encounterId,
                provider:     $response->provider,
                model:        $response->model,
                status:       'schema_invalid',
                attempt:      $attempts,
                systemPrompt: $systemPrompt,
                clinicalNote: $clinicalNote,
                response:     $response,
                errors:       $errors,
            );

            $lastMalformed = $response->content;
            $lastErrors    = $errors;

            // Small backoff before retry so we don't hammer the API on rate-limit edges.
            if ($i < $this->maxRetries) {
                usleep((int) (250_000 * (2 ** $i))); // 250ms, 500ms
            }
        }

        // --- Fallback provider (one shot, no retries) ---
        if ($this->fallback !== null && $this->fallbackModel !== null) {
            $attempts++;
            $systemPrompt = $lastMalformed === null
                ? CodingSystemPrompt::base()
                : CodingSystemPrompt::correction($lastMalformed, $lastErrors);

            try {
                $response = $this->fallback->sendMessage(
                    systemPrompt: $systemPrompt,
                    messages:     [['role' => 'user', 'content' => $clinicalNote]],
                    model:        $this->fallbackModel,
                );

                $totalInputTokens  += $response->inputTokens;
                $totalOutputTokens += $response->outputTokens;
                $totalCost         += $response->costUsd;

                [$decoded, $errors] = $this->decodeAndValidate($response->content);

                if ($decoded !== null) {
                    $this->logAttempt(
                        requestId:    $requestId,
                        encounterId:  $encounterId,
                        provider:     $response->provider,
                        model:        $response->model,
                        status:       'fallback_used',
                        attempt:      $attempts,
                        systemPrompt: $systemPrompt,
                        clinicalNote: $clinicalNote,
                        response:     $response,
                        errors:       null,
                    );

                    return new CodingSuggestionResult(
                        requestId:         $requestId,
                        provider:          $response->provider,
                        model:             $response->model,
                        payload:           $decoded,
                        attemptsMade:      $attempts,
                        totalInputTokens:  $totalInputTokens,
                        totalOutputTokens: $totalOutputTokens,
                        totalCostUsd:      $totalCost,
                    );
                }

                $lastMalformed = $response->content;
                $lastErrors    = $errors;

                $this->logAttempt(
                    requestId:    $requestId,
                    encounterId:  $encounterId,
                    provider:     $response->provider,
                    model:        $response->model,
                    status:       'schema_invalid',
                    attempt:      $attempts,
                    systemPrompt: $systemPrompt,
                    clinicalNote: $clinicalNote,
                    response:     $response,
                    errors:       $errors,
                );
            } catch (LlmTransportException $e) {
                $this->logAttempt(
                    requestId:    $requestId,
                    encounterId:  $encounterId,
                    provider:     $this->fallback->provider(),
                    model:        $this->fallbackModel,
                    status:       'transport_error',
                    attempt:      $attempts,
                    systemPrompt: $systemPrompt,
                    clinicalNote: $clinicalNote,
                    response:     null,
                    errors:       [$e->getMessage()],
                );
            }
        }

        // Both providers exhausted. Caller (queue job) routes to dead-letter.
        throw new MalformedLlmResponseException(
            rawResponse:  $lastMalformed ?? '',
            schemaErrors: $lastErrors,
            attemptsMade: $attempts,
        );
    }

    /**
     * Decode the raw model content and validate against CodingResponseSchema.
     *
     * @return array{0: array|null, 1: list<string>}  [decoded|null, errors]
     */
    private function decodeAndValidate(string $raw): array
    {
        // Claude is well-behaved under our system prompt, but we defensively
        // strip a code fence if the model added one.
        $cleaned = trim($raw);
        if (str_starts_with($cleaned, '```')) {
            $cleaned = preg_replace('/^```(?:json)?\s*/', '', $cleaned);
            $cleaned = preg_replace('/\s*```\s*$/', '', (string) $cleaned);
        }

        try {
            $decoded = json_decode((string) $cleaned, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [null, ['json_decode: ' . $e->getMessage()]];
        }

        if (!is_array($decoded)) {
            return [null, ['json_decode: root must be an object']];
        }

        // opis/json-schema requires an object graph (not assoc array) for $data.
        $dataForValidator = json_decode(json_encode($decoded));

        $validator  = new Validator();
        $schemaJson = CodingResponseSchema::asJson();
        $result     = $validator->validate($dataForValidator, $schemaJson);

        if ($result->isValid()) {
            return [$decoded, []];
        }

        $errorList = [];
        $formatter = new ErrorFormatter();
        foreach ($formatter->format($result->error(), multiple: true) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errorList[] = "{$path}: {$message}";
            }
        }

        return [null, $errorList];
    }

    private function logAttempt(
        string $requestId,
        ?string $encounterId,
        string $provider,
        string $model,
        string $status,
        int $attempt,
        string $systemPrompt,
        string $clinicalNote,
        ?LlmResponse $response,
        ?array $errors,
    ): void {
        try {
            LlmRequest::create([
                'request_id'       => $requestId,
                'encounter_id'     => $encounterId,
                'provider'         => $provider,
                'model'            => $model,
                'status'           => $status,
                'attempt'          => $attempt,
                'request_payload'  => [
                    'system_prompt_sha256' => hash('sha256', $systemPrompt),
                    'clinical_note_length' => strlen($clinicalNote),
                    'clinical_note_sha256' => hash('sha256', $clinicalNote),
                ],
                'response_payload' => $response?->rawResponse,
                'raw_content'      => $response?->content,
                'schema_errors'    => $errors,
                'input_tokens'     => $response?->inputTokens ?? 0,
                'output_tokens'    => $response?->outputTokens ?? 0,
                'cost_usd'         => $response?->costUsd ?? 0.0,
                'duration_ms'      => $response?->durationMs ?? 0,
            ]);
        } catch (\Throwable $e) {
            // Audit logging must NEVER fail the primary request path, but we
            // must alert on it — a missing audit row is a compliance incident.
            Log::critical('llm_requests audit write failed', [
                'request_id' => $requestId,
                'exception'  => $e->getMessage(),
            ]);
        }
    }
}
