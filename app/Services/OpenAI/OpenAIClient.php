<?php

declare(strict_types=1);

namespace App\Services\OpenAI;

use App\Services\Anthropic\Contracts\LlmClient;
use App\Services\Coding\DTOs\LlmResponse;
use App\Services\Coding\Exceptions\LlmTransportException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Chat Completions client — used as the fallback provider.
 *
 * Uses `response_format: json_object` to push the model toward valid JSON,
 * but we still validate post-response via CodingResponseSchema — never trust
 * a provider's "guaranteed" JSON mode.
 */
final class OpenAIClient implements LlmClient
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    private const INPUT_PRICE_PER_MTOK = [
        'gpt-4o'      => 2.50,
        'gpt-4o-mini' => 0.15,
    ];

    private const OUTPUT_PRICE_PER_MTOK = [
        'gpt-4o'      => 10.00,
        'gpt-4o-mini' => 0.60,
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function provider(): string
    {
        return 'openai';
    }

    public function sendMessage(
        string $systemPrompt,
        array $messages,
        string $model,
        int $maxTokens = 4096,
        float $temperature = 0.0,
    ): LlmResponse {
        $payload = [
            'model'           => $model,
            'max_tokens'      => $maxTokens,
            'temperature'     => $temperature,
            'response_format' => ['type' => 'json_object'],
            'messages'        => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages,
            ),
        ];

        $startedAt = microtime(true);

        try {
            $response = $this->http
                ->withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->retry(2, 500, function (\Throwable $e) {
                    return $e instanceof \Illuminate\Http\Client\ConnectionException;
                }, throw: false)
                ->post(self::API_URL, $payload)
                ->throw();
        } catch (RequestException $e) {
            Log::warning('OpenAI API request failed', [
                'status' => $e->response?->status(),
                'body'   => $e->response?->body(),
            ]);
            throw new LlmTransportException(
                provider: $this->provider(),
                previous: $e,
            );
        }

        $durationMs   = (int) round((microtime(true) - $startedAt) * 1000);
        $body         = $response->json();
        $content      = $body['choices'][0]['message']['content'] ?? '';
        $inputTokens  = (int) ($body['usage']['prompt_tokens']     ?? 0);
        $outputTokens = (int) ($body['usage']['completion_tokens'] ?? 0);

        return new LlmResponse(
            provider:     $this->provider(),
            model:        $model,
            content:      (string) $content,
            inputTokens:  $inputTokens,
            outputTokens: $outputTokens,
            costUsd:      $this->calculateCost($model, $inputTokens, $outputTokens),
            durationMs:   $durationMs,
            rawResponse:  $body,
        );
    }

    private function calculateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $inputPrice  = self::INPUT_PRICE_PER_MTOK[$model]  ?? 0.0;
        $outputPrice = self::OUTPUT_PRICE_PER_MTOK[$model] ?? 0.0;

        return round(
            ($inputTokens * $inputPrice + $outputTokens * $outputPrice) / 1_000_000,
            6,
        );
    }
}
