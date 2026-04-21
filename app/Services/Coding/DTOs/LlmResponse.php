<?php

declare(strict_types=1);

namespace App\Services\Coding\DTOs;

final readonly class LlmResponse
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $content,
        public int $inputTokens,
        public int $outputTokens,
        public float $costUsd,
        public int $durationMs,
        public array $rawResponse,
    ) {
    }
}
