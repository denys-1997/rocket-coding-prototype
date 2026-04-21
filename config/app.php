<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;

return [

    'name'      => env('APP_NAME', 'RocketCoding'),
    'env'       => env('APP_ENV', 'production'),
    'debug'     => (bool) env('APP_DEBUG', false),
    'url'       => env('APP_URL', 'http://localhost'),
    'timezone'  => 'UTC',
    'locale'    => 'en',
    'fallback_locale' => 'en',
    'faker_locale'    => 'en_US',
    'cipher'    => 'AES-256-CBC',
    'key'       => env('APP_KEY'),
    'previous_keys' => [],

    'maintenance' => [
        'driver' => 'file',
    ],

    'providers' => [
        App\Providers\AppServiceProvider::class,
    ],

    'aliases' => Facade::defaultAliases()->toArray(),

];
