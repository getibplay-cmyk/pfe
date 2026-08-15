<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\DownloadFleetReallocationProposal;
use App\Actions\Intelligence\ImportFleetReallocationProposal;
use App\Actions\Intelligence\RecordFleetReallocationDecision;
use App\Enums\IntelligenceResultBatchDecision;
use App\Exceptions\FleetReallocationValidationException;
use App\Http\Requests\ImportFleetReallocationProposalRequest;
use App\Http\Requests\RecordFleetReallocationDecisionRequest;
use App\Models\FleetReallocationProposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FleetReallocationProposalController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', FleetReallocationProposal::class);

        return view('intelligence.fleet-reallocation.index', [
            'proposals' => FleetReallocationProposal::query()
                ->with(['moves', 'decision.actor'])
                ->latest('imported_at')
                ->latest('id')
                ->paginate(20),
            'canImport' => auth()->user()->agency_id === null
                && auth()->user()->hasPermission('prediction.demo.review'),
            'acceptReasonCodes' => RecordFleetReallocationDecisionRequest::ACCEPT_REASON_CODES,
            'rejectReasonCodes' => RecordFleetReallocationDecisionRequest::REJECT_REASON_CODES,
        ]);
    }

    public function store(
        ImportFleetReallocationProposalRequest $request,
        ImportFleetReallocationProposal $import,
    ): RedirectResponse {
        $file = $request->file('proposal');
        abort_unless($file instanceof UploadedFile, 422);

        try {
            $result = $import->handle($file, $request->user());
        } catch (FleetReallocationValidationException $exception) {
            throw ValidationException::withMessages([
                'proposal' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('intelligence.fleet-reallocation.index')->with(
            'status',
            $result->created
                ? 'Proposition OR-Tools synthétique importée sans effet opérationnel.'
                : 'Proposition déjà présente : rejeu idempotent sans duplication.',
        );
    }

    public function decide(
        RecordFleetReallocationDecisionRequest $request,
        FleetReallocationProposal $reallocationProposal,
        RecordFleetReallocationDecision $record,
    ): RedirectResponse {
        $data = $request->validated();
        $record->handle(
            $reallocationProposal,
            $request->user(),
            IntelligenceResultBatchDecision::from($data['decision']),
            $data['reason_code'],
        );

        return redirect()->route('intelligence.fleet-reallocation.index')
            ->with('status', 'Décision humaine enregistrée sans action sur la flotte.');
    }

    public function download(
        FleetReallocationProposal $reallocationProposal,
        DownloadFleetReallocationProposal $download,
    ): StreamedResponse {
        $this->authorize('view', $reallocationProposal);

        return $download->handle($reallocationProposal);
    }
}
