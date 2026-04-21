<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api:     __DIR__ . '/../routes/api.php',
        commands:__DIR__ . '/../routes/console.php',
        health:  '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Services\Coding\Exceptions\MalformedLlmResponseException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error'         => 'coding_suggestion_failed',
                    'message'       => 'Unable to produce valid coding suggestions after retries and fallback.',
                    'attempts_made' => $e->attemptsMade,
                ], 422);
            }
        });
    })->create();
