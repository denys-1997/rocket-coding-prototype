<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string                 $request_id
 * @property string                 $provider
 * @property string                 $model
 * @property string                 $status         ok | schema_invalid | transport_error | fallback_used
 * @property int                    $attempt
 * @property array                  $request_payload
 * @property array|null             $response_payload
 * @property string|null            $raw_content
 * @property array|null             $schema_errors
 * @property int                    $input_tokens
 * @property int                    $output_tokens
 * @property float                  $cost_usd
 * @property int                    $duration_ms
 * @property string|null            $encounter_id
 * @property Carbon                 $created_at
 */
final class LlmRequest extends Model
{
    // Audit rows are immutable. Laravel's UPDATED_AT is disabled and updates
    // are blocked at the application layer. In production this table would
    // additionally have a DB user with INSERT-only grants.
    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'provider',
        'model',
        'status',
        'attempt',
        'request_payload',
        'response_payload',
        'raw_content',
        'schema_errors',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'duration_ms',
        'encounter_id',
    ];

    protected $casts = [
        'request_payload'  => AsArrayObject::class,
        'response_payload' => AsArrayObject::class,
        'schema_errors'    => 'array',
        'cost_usd'         => 'decimal:6',
        'input_tokens'     => 'integer',
        'output_tokens'    => 'integer',
        'duration_ms'      => 'integer',
        'attempt'          => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (LlmRequest $model): bool {
            // Hard block: audit rows are append-only.
            return false;
        });
    }
}
