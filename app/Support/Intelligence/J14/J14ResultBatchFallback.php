<?php

namespace App\Support\Intelligence\J14;

use App\Models\IntelligenceResultBatch;
use App\Support\Tenancy\TenantContext;

final class J14ResultBatchFallback
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly J14ResultBatchArtifactVerifier $verifier,
    ) {}

    public function resolve(): J14ResultBatchFallbackState
    {
        $candidates = IntelligenceResultBatch::query()
            ->select('intelligence_result_batches.*')
            ->join(
                'intelligence_result_batch_decisions as result_decisions',
                'result_decisions.intelligence_result_batch_id',
                '=',
                'intelligence_result_batches.id',
            )
            ->where('result_decisions.decision', 'accepted_for_demo_review')
            ->when(
                $this->context->agencyId(),
                fn ($query, $agencyId) => $query->where('intelligence_result_batches.agency_id', $agencyId),
            )
            ->orderByDesc('result_decisions.created_at')
            ->cursor();

        foreach ($candidates as $candidate) {
            if ($this->verifier->valid($candidate)) {
                $candidate->load('decision');

                return new J14ResultBatchFallbackState(
                    $candidate,
                    (int) $candidate->decision->created_at->diffInSeconds(now()),
                );
            }
        }

        return new J14ResultBatchFallbackState(null, null);
    }
}
