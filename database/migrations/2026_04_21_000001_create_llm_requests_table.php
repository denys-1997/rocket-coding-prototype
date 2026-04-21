<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_requests', function (Blueprint $table) {
            $table->id();

            // Logical request ID — shared across retries of the same coding request.
            // Lets us correlate all attempts for one encounter under one audit trail.
            $table->uuid('request_id')->index();
            $table->string('encounter_id')->nullable()->index();

            $table->string('provider', 32);
            $table->string('model', 64);

            // ok | schema_invalid | transport_error | fallback_used
            $table->string('status', 32)->index();
            $table->unsignedTinyInteger('attempt')->default(1);

            // Full request and response payloads for audit reconstruction.
            // In production these are field-encrypted at rest (HIPAA).
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->longText('raw_content')->nullable();
            $table->json('schema_errors')->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);

            // Immutable: no updated_at column.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'status', 'created_at'], 'llm_requests_provider_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_requests');
    }
};
