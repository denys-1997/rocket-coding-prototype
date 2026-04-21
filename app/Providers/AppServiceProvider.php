<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Anthropic\ClaudeClient;
use App\Services\Anthropic\Contracts\LlmClient;
use App\Services\Coding\CodingSuggestionService;
use App\Services\OpenAI\OpenAIClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Primary provider — registered on the interface so tests can swap
        // it via $this->app->instance(LlmClient::class, $fake).
        $this->app->bind(LlmClient::class, function (Container $app): LlmClient {
            return new ClaudeClient(
                http:           $app->make(HttpFactory::class),
                apiKey:         (string) config('services.anthropic.api_key'),
                timeoutSeconds: (int) config('llm.timeout_seconds', 60),
            );
        });

        // OpenAI fallback — optional. Registered under a named binding so
        // the service can resolve it without colliding with the primary.
        $this->app->bind('llm.fallback', function (Container $app): ?LlmClient {
            if (config('llm.fallback.provider') !== 'openai') {
                return null;
            }

            return new OpenAIClient(
                http:           $app->make(HttpFactory::class),
                apiKey:         (string) config('services.openai.api_key'),
                timeoutSeconds: (int) config('llm.timeout_seconds', 60),
            );
        });

        $this->app->bind(CodingSuggestionService::class, function (Container $app): CodingSuggestionService {
            return new CodingSuggestionService(
                primary:       $app->make(LlmClient::class),
                fallback:      $app->make('llm.fallback'),
                primaryModel:  (string) config('llm.primary.model'),
                fallbackModel: config('llm.fallback.model'),
                maxRetries:    (int) config('llm.max_retries', 2),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
