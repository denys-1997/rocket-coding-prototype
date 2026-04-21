<?php

declare(strict_types=1);

namespace App\Services\Anthropic;

use App\Services\Anthropic\Contracts\LlmClient;
use App\Services\Coding\DTOs\LlmResponse;
use App\Services\Coding\Exceptions\LlmTransportException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

final class ClaudeClient implements LlmClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    private const INPUT_PRICE_PER_MTOK = [
        'claude-opus-4-7'      => 15.00,
        'claude-sonnet-4-6'    => 3.00,
        'claude-haiku-4-5'     => 0.80,
    ];

    private const OUTPUT_PRICE_PER_MTOK = [
        'claude-opus-4-7'      => 75.00,
        'claude-sonnet-4-6'    => 15.00,
        'claude-haiku-4-5'     => 4.00,
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function sendMessage(
        string $systemPrompt,
        array $messages,
        string $model,
        int $maxTokens = 4096,
        float $temperature = 0.0,
    ): LlmResponse {
        $payload = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'system'      => $systemPrompt,
            'messages'    => $messages,
        ];

        $startedAt = microtime(true);

        try {
            $response = $this->http
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'      => 'application/json',
                ])
                ->timeout($this->timeoutSeconds)
                ->retry(2, 500, function (\Throwable $e) {
                    // Retry only on transient network errors, NOT on 4xx.
                    return $e instanceof \Illuminate\Http\Client\ConnectionException;
                }, throw: false)
                ->post(self::API_URL, $payload)
                ->throw();
        } catch (RequestException $e) {
            Log::warning('Anthropic API request failed', [
                'status' => $e->response?->status(),
                'body'   => $e->response?->body(),
            ]);
            throw new LlmTransportException(
                provider: $this->provider(),
                previous: $e,
            );
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $body       = $response->json();

        $content      = $this->extractTextContent($body);
        $inputTokens  = (int) ($body['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($body['usage']['output_tokens'] ?? 0);

        return new LlmResponse(
            provider:     $this->provider(),
            model:        $model,
            content:      $content,
            inputTokens:  $inputTokens,
            outputTokens: $outputTokens,
            costUsd:      $this->calculateCost($model, $inputTokens, $outputTokens),
            durationMs:   $durationMs,
            rawResponse:  $body,
        );
    }

    /**
     * Anthropic returns a content array of typed blocks. For structured-output
     * requests we only care about the concatenated text across text blocks.
     */
    private function extractTextContent(array $body): string
    {
        $blocks = $body['content'] ?? [];

        return collect($blocks)
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
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
