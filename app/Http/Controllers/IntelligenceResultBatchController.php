<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\DownloadIntelligenceResultBatch;
use App\Actions\Intelligence\ImportIntelligenceResultBatch;
use App\Actions\Intelligence\RecordIntelligenceResultBatchDecision;
use App\Enums\IntelligenceResultBatchDecision;
use App\Exceptions\J14ResultBatchValidationException;
use App\Http\Requests\ImportIntelligenceResultBatchRequest;
use App\Http\Requests\RecordIntelligenceResultBatchDecisionRequest;
use App\Models\IntelligenceDatasetExportRun;
use App\Models\IntelligenceResultBatch;
use App\Support\Intelligence\J14\J14ResultBatchFallback;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntelligenceResultBatchController extends Controller
{
    public function index(
        Request $request,
        TenantContext $context,
        J14ResultBatchFallback $fallback,
    ): View {
        $this->authorize('viewAny', IntelligenceResultBatch::class);

        $batches = IntelligenceResultBatch::query()
            ->with(['agency', 'exportRun', 'decision.actor'])
            ->withCount('rows')
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->latest('imported_at')
            ->latest('id')
            ->paginate(25);

        $exportRuns = collect();
        if ($request->user()->hasPermission('prediction.demo.review')) {
            $exportRuns = IntelligenceDatasetExportRun::query()
                ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
                ->latest('created_at')
                ->latest('id')
                ->limit(10)
                ->get();
        }

        return view('intelligence.result-batches.index', [
            'batches' => $batches,
            'exportRuns' => $exportRuns,
            'fallback' => $fallback->resolve(),
            'acceptReasonCodes' => RecordIntelligenceResultBatchDecisionRequest::ACCEPT_REASON_CODES,
            'rejectReasonCodes' => RecordIntelligenceResultBatchDecisionRequest::REJECT_REASON_CODES,
        ]);
    }

    public function store(
        ImportIntelligenceResultBatchRequest $request,
        IntelligenceDatasetExportRun $exportRun,
        ImportIntelligenceResultBatch $import,
    ): RedirectResponse {
        $file = $request->file('result_batch');
        abort_unless($file instanceof UploadedFile, 422);

        try {
            $result = $import->handle($exportRun, $file, $request->user());
        } catch (J14ResultBatchValidationException $exception) {
            throw ValidationException::withMessages([
                'result_batch' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('intelligence.result-batches.index')->with(
            'status',
            $result->created
                ? 'Lot J14-B validé et importé sans effet opérationnel.'
                : 'Lot J14-B déjà présent : rejeu idempotent sans duplication.',
        );
    }

    public function decide(
        RecordIntelligenceResultBatchDecisionRequest $request,
        IntelligenceResultBatch $resultBatch,
        RecordIntelligenceResultBatchDecision $record,
    ): RedirectResponse {
        $data = $request->validated();
        $record->handle(
            $resultBatch,
            $request->user(),
            IntelligenceResultBatchDecision::from($data['decision']),
            $data['reason_code'],
        );

        return redirect()->route('intelligence.result-batches.index')
            ->with('status', 'Décision humaine J14-B enregistrée sans action métier.');
    }

    public function download(
        IntelligenceResultBatch $resultBatch,
        DownloadIntelligenceResultBatch $download,
    ): StreamedResponse {
        $this->authorize('view', $resultBatch);

        return $download->handle($resultBatch);
    }
}
