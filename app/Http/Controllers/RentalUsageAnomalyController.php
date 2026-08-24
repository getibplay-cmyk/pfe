<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\QueueRentalUsageAnomalyRun;
use App\Actions\Intelligence\RecordRentalUsageAnomalyReview;
use App\Enums\RentalUsageAnomalyReviewDecision;
use App\Exceptions\RentalUsageAnomalyAlreadyActiveException;
use App\Exceptions\RentalUsageAnomalyExecutionException;
use App\Http\Requests\ReviewRentalUsageAnomalyRequest;
use App\Models\IntelligenceDatasetExportRun;
use App\Models\RentalUsageAnomalyResult;
use App\Models\RentalUsageAnomalyRun;
use App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyContract;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RentalUsageAnomalyController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $this->authorize('viewAny', RentalUsageAnomalyRun::class);
        $validated = $request->validate([
            'run' => ['nullable', 'uuid'],
            'budget' => ['nullable', Rule::in(['50', '100', '200', 50, 100, 200])],
        ]);
        $budget = (int) ($validated['budget'] ?? RentalUsageAnomalyContract::DEFAULT_BUDGET_BASIS_POINTS);
        $runsQuery = RentalUsageAnomalyRun::query()
            ->with(['exportRun', 'requester'])
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->latest('requested_at')
            ->latest('id');
        $selectedRun = isset($validated['run'])
            ? (clone $runsQuery)->where('run_id', $validated['run'])->firstOrFail()
            : (clone $runsQuery)->first();
        if ($selectedRun !== null) {
            $this->authorize('view', $selectedRun);
        }

        $selectionColumn = match ($budget) {
            50 => 'primary_selected_005',
            200 => 'primary_selected_020',
            default => 'primary_selected_010',
        };
        $results = $selectedRun?->status->value === 'succeeded' && $selectedRun->data_status === 'usable'
            ? RentalUsageAnomalyResult::query()
                ->with(['rentalContract.vehicle', 'agency', 'latestReview.reviewer'])
                ->withCount('reviews')
                ->where('rental_usage_anomaly_run_id', $selectedRun->id)
                ->where($selectionColumn, true)
                ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
                ->orderBy('primary_rank')
                ->get()
            : collect();

        $canRun = (bool) config('intelligence.rental_usage_anomaly.enabled')
            && $request->user()->hasPermission('prediction.anomaly.review')
            && (string) config('intelligence.rental_usage_anomaly.python_binary') !== ''
            && is_file((string) config('intelligence.rental_usage_anomaly.runtime_script'));
        $exports = $request->user()->hasPermission('prediction.anomaly.review')
            ? IntelligenceDatasetExportRun::query()
                ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
                ->latest('created_at')
                ->latest('id')
                ->limit(20)
                ->get()
            : collect();

        return view('intelligence.rental-usage-anomalies.index', [
            'runs' => $runsQuery->paginate(15)->withQueryString(),
            'selectedRun' => $selectedRun,
            'results' => $results,
            'exports' => $exports,
            'budget' => $budget,
            'canRun' => $canRun,
            'canReview' => $request->user()->hasPermission('prediction.anomaly.review'),
            'runtime' => [
                'enabled' => (bool) config('intelligence.rental_usage_anomaly.enabled'),
                'ready' => $canRun,
                'compute' => 'CPU',
                'minimum_rows' => RentalUsageAnomalyContract::MINIMUM_ROWS,
            ],
        ]);
    }

    public function store(
        Request $request,
        IntelligenceDatasetExportRun $exportRun,
        QueueRentalUsageAnomalyRun $queue,
    ): RedirectResponse {
        $this->authorize('create', RentalUsageAnomalyRun::class);
        try {
            $run = $queue->handle($exportRun, $request->user());
        } catch (RentalUsageAnomalyAlreadyActiveException) {
            throw ValidationException::withMessages([
                'export_run' => 'Une analyse de ce snapshot est déjà dans la queue Intelligence.',
            ]);
        } catch (RentalUsageAnomalyExecutionException $exception) {
            $message = match ($exception->failureCode()) {
                'SOURCE_SNAPSHOT_INVALID' => 'Le snapshot privé est absent ou son intégrité a changé. Régénérez un export RentFleet v1.1 avant de relancer l’analyse.',
                'RUNTIME_CONFIGURATION_INVALID' => 'Le runtime CPU des usages atypiques est indisponible. Vérifiez la configuration avant de relancer l’analyse.',
                'QUEUE_DISPATCH_FAILED' => 'La queue Intelligence est momentanément indisponible. Réessayez après vérification du worker.',
                default => 'L’analyse consultative n’a pas pu être ajoutée à la queue Intelligence.',
            };

            throw ValidationException::withMessages(['export_run' => $message]);
        }

        return redirect()->route('intelligence.rental-usage-anomalies.index', ['run' => $run->run_id])
            ->with('status', 'Classement CPU ajouté à la queue Intelligence, sans action métier automatique.');
    }

    public function review(
        ReviewRentalUsageAnomalyRequest $request,
        RentalUsageAnomalyResult $anomalyResult,
        RecordRentalUsageAnomalyReview $record,
    ): RedirectResponse {
        $data = $request->validated();
        $note = isset($data['note']) && trim((string) $data['note']) !== ''
            ? trim((string) $data['note'])
            : null;
        $record->handle(
            $anomalyResult,
            $request->user(),
            RentalUsageAnomalyReviewDecision::from($data['decision']),
            $note,
        );

        return redirect()->route('intelligence.rental-usage-anomalies.index', [
            'run' => $anomalyResult->run->run_id,
            'budget' => $request->integer('budget', RentalUsageAnomalyContract::DEFAULT_BUDGET_BASIS_POINTS),
        ])->with('status', 'Revue humaine ajoutée au registre append-only, sans sanction, frais ni modification de contrat.');
    }
}
