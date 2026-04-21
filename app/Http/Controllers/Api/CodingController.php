<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreCodingSuggestionRequest;
use App\Jobs\ProcessClinicalDocumentJob;
use App\Models\LlmRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class CodingController extends Controller
{
    /**
     * Queue a coding-suggestion job for an encounter.
     *
     * Thin controller: validate → dispatch → 202. All LLM orchestration
     * happens asynchronously in {@see ProcessClinicalDocumentJob}.
     */
    public function store(StoreCodingSuggestionRequest $request): JsonResponse
    {
        $data = $request->validated();

        ProcessClinicalDocumentJob::dispatch(
            encounterId:  $data['encounter_id'],
            clinicalNote: $data['clinical_note'],
        );

        return response()->json([
            'status'       => 'accepted',
            'encounter_id' => $data['encounter_id'],
        ], 202);
    }

    /**
     * Retrieve the audit trail for a given encounter.
     *
     * Returns every LLM attempt (ok / schema_invalid / transport_error /
     * fallback_used) in order. Used by coder-review UI and by OIG auditors.
     */
    public function auditTrail(string $encounterId): JsonResponse
    {
        $rows = LlmRequest::query()
            ->where('encounter_id', $encounterId)
            ->orderBy('created_at')
            ->orderBy('attempt')
            ->get();

        return response()->json([
            'encounter_id' => $encounterId,
            'attempts'     => $rows->map(fn (LlmRequest $r) => [
                'request_id'    => $r->request_id,
                'provider'      => $r->provider,
                'model'         => $r->model,
                'status'        => $r->status,
                'attempt'       => $r->attempt,
                'input_tokens'  => $r->input_tokens,
                'output_tokens' => $r->output_tokens,
                'cost_usd'      => $r->cost_usd,
                'duration_ms'   => $r->duration_ms,
                'schema_errors' => $r->schema_errors,
                'created_at'    => $r->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
