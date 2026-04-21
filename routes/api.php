<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CodingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| v1 API
|--------------------------------------------------------------------------
|
| All endpoints are versioned under /api/v1. Breaking changes ship under
| /api/v2 with both versions active through a deprecation window.
|
| Authentication is Sanctum bearer tokens in production. Rate limiting is
| per-tenant via a `tenant` throttle group (60 coding submissions / minute,
| 600 audit-trail reads / minute).
|
*/

Route::prefix('v1')
    ->middleware(['throttle:api'])
    ->group(function () {
        Route::post('coding/suggestions', [CodingController::class, 'store'])
            ->name('api.v1.coding.suggestions.store');

        Route::get('coding/audit/{encounterId}', [CodingController::class, 'auditTrail'])
            ->name('api.v1.coding.audit.show')
            ->whereAlphaNumeric('encounterId');
    });
