<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Coding\CodingSuggestionService;
use App\Services\Coding\Exceptions\MalformedLlmResponseException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes a single encounter's clinical note → coding suggestions.
 *
 * The controller dispatches this job and returns 202 Accepted immediately;
 * the caller (React frontend) polls a results endpoint or subscribes to a
 * WebSocket channel for the final suggestion.
 *
 * Tries: 2 total (one retry) at the job level. The *service* already retries
 * schema-failures internally up to LLM_MAX_RETRIES, so a job-level retry is
 * only for transport/DB issues not caught by the service.
 */
final class ProcessClinicalDocumentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $backoff = 30;
    public int $timeout = 120;

    public function __construct(
        public readonly string $encounterId,
        public readonly string $clinicalNote,
    ) {
        $this->onQueue('llm');
    }

    public function uniqueId(): string
    {
        return $this->encounterId;
    }

    public function handle(CodingSuggestionService $service): void
    {
        try {
            $result = $service->suggestCodes(
                clinicalNote: $this->clinicalNote,
                encounterId:  $this->encounterId,
            );
        } catch (MalformedLlmResponseException $e) {
            Log::warning('Coding suggestion failed — routing to dead-letter', [
                'encounter_id'  => $this->encounterId,
                'attempts_made' => $e->attemptsMade,
                'schema_errors' => $e->schemaErrors,
            ]);

            // A malformed response after all retries + fallback is NOT a
            // retryable job failure — retrying would just waste tokens and
            // produce the same result. Fail permanently so it lands in the
            // failed_jobs table for human review.
            $this->fail($e);

            return;
        }

        // TODO in full product: persist result to `coding_suggestions` table
        // and notify the frontend via WebSocket channel `encounter.{id}`.
        Log::info('Coding suggestion ready', [
            'encounter_id'   => $this->encounterId,
            'request_id'     => $result->requestId,
            'provider'       => $result->provider,
            'model'          => $result->model,
            'attempts_made'  => $result->attemptsMade,
            'total_cost_usd' => $result->totalCostUsd,
            'code_counts'    => [
                'cpt'   => count($result->payload['cpt_codes']   ?? []),
                'icd10' => count($result->payload['icd10_codes'] ?? []),
            ],
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProcessClinicalDocumentJob permanently failed', [
            'encounter_id' => $this->encounterId,
            'exception'    => $e->getMessage(),
        ]);

        // In production: notify on-call via PagerDuty if the failure rate
        // over the last 15 minutes exceeds threshold.
    }
}
