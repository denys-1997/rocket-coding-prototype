<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace'   => (bool) env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver'            => 'stack',
            'channels'          => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver'     => 'monolog',
            'level'      => env('LOG_LEVEL', 'debug'),
            'handler'    => StreamHandler::class,
            'formatter'  => env('LOG_STDERR_FORMATTER'),
            'with'       => ['stream' => 'php://stderr'],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'null' => [
            'driver'  => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],

    ],

];
