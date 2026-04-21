<?php

declare(strict_types=1);

namespace App\Services\Coding\Exceptions;

use RuntimeException;

final class MalformedLlmResponseException extends RuntimeException
{
    /**
     * @param  list<string>  $schemaErrors
     */
    public function __construct(
        public readonly string $rawResponse,
        public readonly array $schemaErrors,
        public readonly int $attemptsMade,
    ) {
        parent::__construct(
            sprintf(
                'LLM returned malformed response after %d attempts: %s',
                $attemptsMade,
                implode('; ', $schemaErrors) ?: 'JSON decode failure',
            ),
        );
    }
}
