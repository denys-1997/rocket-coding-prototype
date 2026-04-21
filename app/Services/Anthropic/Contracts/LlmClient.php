<?php

declare(strict_types=1);

namespace App\Services\Anthropic\Contracts;

use App\Services\Coding\DTOs\LlmResponse;

interface LlmClient
{
    /**
     * Send a structured-output request to the underlying LLM and return the
     * raw assistant message plus usage metadata.
     *
     * Implementations MUST NOT parse or validate the returned content — that
     * is the service layer's responsibility. Implementations MUST surface the
     * raw response body so the audit log can persist it verbatim.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function sendMessage(
        string $systemPrompt,
        array $messages,
        string $model,
        int $maxTokens = 4096,
        float $temperature = 0.0,
    ): LlmResponse;

    public function provider(): string;
}
