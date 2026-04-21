<?php

declare(strict_types=1);

namespace App\Services\Coding\Exceptions;

use RuntimeException;
use Throwable;

final class LlmTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "LLM transport error from provider [{$provider}]",
            previous: $previous,
        );
    }
}
