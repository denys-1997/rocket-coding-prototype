<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Anthropic\Contracts\LlmClient;
use App\Services\Coding\DTOs\LlmResponse;
use App\Services\Coding\Exceptions\LlmTransportException;
use PHPUnit\Framework\Assert;

/**
 * Scripted LLM client for tests.
 *
 * Push a sequence of responses (strings), exceptions, or closures via
 * queueResponse()/queueException(). Each call to sendMessage() pops the
 * next item. Use assertSequenceConsumed() at end of test to assert the
 * scripted sequence matched actual calls.
 */
final class FakeLlmClient implements LlmClient
{
    /** @var list<string|\Throwable|\Closure> */
    private array $queue = [];

    /** @var list<array{systemPrompt: string, messages: array, model: string}> */
    public array $calls = [];

    public function __construct(private readonly string $provider = 'anthropic')
    {
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function queueResponse(string|array $content): self
    {
        $this->queue[] = is_array($content)
            ? json_encode($content, JSON_THROW_ON_ERROR)
            : $content;

        return $this;
    }

    public function queueException(\Throwable $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function queueTransportError(): self
    {
        return $this->queueException(new LlmTransportException($this->provider));
    }

    public function sendMessage(
        string $systemPrompt,
        array $messages,
        string $model,
        int $maxTokens = 4096,
        float $temperature = 0.0,
    ): LlmResponse {
        $this->calls[] = compact('systemPrompt', 'messages', 'model');

        Assert::assertNotEmpty(
            $this->queue,
            "FakeLlmClient ({$this->provider}) called more times than scripted. Queue is empty.",
        );

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return new LlmResponse(
            provider:     $this->provider,
            model:        $model,
            content:      (string) $next,
            inputTokens:  42,
            outputTokens: 128,
            costUsd:      0.001,
            durationMs:   50,
            rawResponse:  ['fake' => true, 'content' => $next],
        );
    }

    public function assertSequenceConsumed(): void
    {
        Assert::assertEmpty(
            $this->queue,
            sprintf(
                'FakeLlmClient (%s) had %d unused scripted responses.',
                $this->provider,
                count($this->queue),
            ),
        );
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
