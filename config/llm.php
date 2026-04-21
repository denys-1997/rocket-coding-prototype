<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | LLM providers & models
    |--------------------------------------------------------------------------
    |
    | Primary = Claude. Fallback = OpenAI. The CodingSuggestionService is
    | provider-agnostic and will use whichever clients are wired into the
    | container in AppServiceProvider.
    |
    */

    'primary' => [
        'provider' => 'anthropic',
        'model'    => env('LLM_PRIMARY_MODEL', 'claude-opus-4-7'),
    ],

    'fallback' => [
        'provider' => env('LLM_FALLBACK_ENABLED', true) ? 'openai' : null,
        'model'    => env('LLM_FALLBACK_MODEL', 'gpt-4o'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry behaviour
    |--------------------------------------------------------------------------
    |
    | max_retries applies to schema-validation failures on the primary
    | provider. A value of 2 means we make up to 3 primary attempts total
    | (initial + 2 retries) before falling back.
    |
    */

    'max_retries'     => (int) env('LLM_MAX_RETRIES', 2),
    'timeout_seconds' => (int) env('LLM_TIMEOUT_SECONDS', 60),

];
