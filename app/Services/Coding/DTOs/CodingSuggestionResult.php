<?php

declare(strict_types=1);

namespace App\Services\Coding\DTOs;

final readonly class CodingSuggestionResult
{
    public function __construct(
        public string $requestId,
        public string $provider,
        public string $model,
        public array $payload,
        public int $attemptsMade,
        public int $totalInputTokens,
        public int $totalOutputTokens,
        public float $totalCostUsd,
    ) {
    }

    public function toArray(): array
    {
        return [
            'request_id'          => $this->requestId,
            'provider'            => $this->provider,
            'model'               => $this->model,
            'payload'             => $this->payload,
            'attempts_made'       => $this->attemptsMade,
            'total_input_tokens'  => $this->totalInputTokens,
            'total_output_tokens' => $this->totalOutputTokens,
            'total_cost_usd'      => $this->totalCostUsd,
        ];
    }
}
