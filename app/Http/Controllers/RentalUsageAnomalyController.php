<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\QueueRentalUsageAnomalyRun;
use App\Actions\Intelligence\RecordRentalUsageAnomalyReview;
use App\Enums\RentalUsageAnomalyReviewDecision;
use App\Exceptions\RentalUsageAnomalyAlreadyActiveException;
use App\Exceptions\RentalUsageAnomalyExecutionException;
use App\Http\Requests\RentalUsageAnomalyFilterRequest;
use App\Http\Requests\ReviewRentalUsageAnomalyRequest;
use App\Models\Agency;
use App\Models\IntelligenceDatasetExportRun;
use App\Models\RentalContract;
use App\Models\RentalUsageAnomalyResult;
use App\Models\RentalUsageAnomalyRun;
use App\Support\Intelligence\RentalUsageAnomaly\FindCanonicalRentalUsageAnomaly;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RentalUsageAnomalyController extends Controller
{
    public function index(RentalUsageAnomalyFilterRequest $request, TenantContext $context): View
    {
        $agencyId = $request->agencyId();
        $dateFrom = $request->dateFrom();
        $dateTo = $request->dateTo();
        $reviewState = $request->reviewState();

        $runsQuery = RentalUsageAnomalyRun::query()
            ->with('agency')
            ->succeededUsable()
            ->whereHas('results', fn ($query) => $query
                ->canonicalReviewCandidate()
                ->where('event_at', '>=', $dateFrom->utc())
                ->where('event_at', '<', $dateTo->addDay()->utc())
                ->when($agencyId, fn ($resultQuery, $id) => $resultQuery->where('agency_id', $id)))
            ->latest('requested_at')
            ->latest('id');

        $selectedRun = (clone $runsQuery)->first();
        if ($selectedRun !== null) {
            $this->authorize('view', $selectedRun);
        }

        $resultsQuery = RentalUsageAnomalyResult::query()->whereRaw('1 = 0');
        if ($selectedRun !== null) {
            $resultsQuery = RentalUsageAnomalyResult::query()
                ->with(['rentalContract', 'agency', 'latestReview'])
                ->canonicalReviewCandidate()
                ->where('rental_usage_anomaly_run_id', $selectedRun->id)
                ->where('event_at', '>=', $dateFrom->utc())
                ->where('event_at', '<', $dateTo->addDay()->utc())
                ->when($agencyId, fn ($query, $id) => $query->where('agency_id', $id))
                ->when($reviewState === 'pending', fn ($query) => $query->doesntHave('reviews'))
                ->when(
                    $reviewState !== null && $reviewState !== 'pending',
                    fn ($query) => $query->whereHas(
                        'latestReview',
                        fn ($reviewQuery) => $reviewQuery->where('decision', $reviewState),
                    ),
                )
                ->orderBy('primary_rank');
        }
        $results = $resultsQuery->paginate(15)->withQueryString();

        $canRun = (bool) config('intelligence.rental_usage_anomaly.enabled')
            && $request->user()->hasPermission('prediction.anomaly.review')
            && (string) config('intelligence.rental_usage_anomaly.python_binary') !== ''
            && is_file((string) config('intelligence.rental_usage_anomaly.runtime_script'));
        $launchSourceAvailable = $canRun && IntelligenceDatasetExportRun::query()
            ->when($context->agencyId(), fn ($query, $id) => $query->where('agency_id', $id))
            ->exists();
        $agencies = Agency::query()
            ->where('is_active', true)
            ->when($context->agencyId(), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('intelligence.rental-usage-anomalies.index', [
            'selectedRun' => $selectedRun,
            'results' => $results,
            'agencies' => $agencies,
            'filters' => [
                'agency' => $agencyId,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'review_state' => $reviewState,
            ],
            'canRun' => $canRun,
            'canReview' => $request->user()->hasPermission('prediction.anomaly.review'),
            'canExport' => $request->user()->hasPermission('prediction.export'),
            'launchSourceAvailable' => $launchSourceAvailable,
        ]);
    }

    public function storeLatest(
        Request $request,
        TenantContext $context,
        QueueRentalUsageAnomalyRun $queue,
    ): RedirectResponse {
        $this->authorize('create', RentalUsageAnomalyRun::class);
        $export = IntelligenceDatasetExportRun::query()
            ->when($context->agencyId(), fn ($query, $id) => $query->where('agency_id', $id))
            ->latest('created_at')
            ->latest('id')
            ->first();
        if ($export === null) {
            throw ValidationException::withMessages([
                'analysis' => 'Aucune source de données préparée n’est disponible dans votre périmètre.',
            ]);
        }

        return $this->queueExport($request, $export, $queue);
    }

    public function store(
        Request $request,
        IntelligenceDatasetExportRun $exportRun,
        QueueRentalUsageAnomalyRun $queue,
    ): RedirectResponse {
        $this->authorize('create', RentalUsageAnomalyRun::class);

        return $this->queueExport($request, $exportRun, $queue);
    }

    public function reviewForContract(
        ReviewRentalUsageAnomalyRequest $request,
        RentalContract $contract,
        FindCanonicalRentalUsageAnomaly $finder,
        RecordRentalUsageAnomalyReview $record,
    ): RedirectResponse {
        $result = $finder->forContract($contract);
        abort_if($result === null, 404);
        $this->authorize('review', $result);

        return $this->recordReview($request, $result, $record);
    }

    public function review(
        ReviewRentalUsageAnomalyRequest $request,
        RentalUsageAnomalyResult $anomalyResult,
        RecordRentalUsageAnomalyReview $record,
    ): RedirectResponse {
        return $this->recordReview($request, $anomalyResult, $record);
    }

    private function queueExport(
        Request $request,
        IntelligenceDatasetExportRun $exportRun,
        QueueRentalUsageAnomalyRun $queue,
    ): RedirectResponse {
        try {
            $queue->handle($exportRun, $request->user());
        } catch (RentalUsageAnomalyAlreadyActiveException) {
            throw ValidationException::withMessages([
                'analysis' => 'Une analyse est déjà en cours pour les données préparées.',
            ]);
        } catch (RentalUsageAnomalyExecutionException $exception) {
            $message = match ($exception->failureCode()) {
                'SOURCE_SNAPSHOT_INVALID' => 'Les données préparées ne sont plus disponibles. Préparez une nouvelle analyse depuis Intelligence.',
                'RUNTIME_CONFIGURATION_INVALID' => 'L’analyse est temporairement indisponible.',
                'QUEUE_DISPATCH_FAILED' => 'L’analyse n’a pas pu être planifiée. Réessayez dans quelques instants.',
                default => 'L’analyse consultative n’a pas pu être planifiée.',
            };

            throw ValidationException::withMessages(['analysis' => $message]);
        }

        return redirect()->route('intelligence.rental-usage-anomalies.index')
            ->with('status', 'Analyse ajoutée à la file de traitement. Les données métier restent inchangées.');
    }

    private function recordReview(
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
        $returnDate = $anomalyResult->event_at
            ->timezone((string) config('app.timezone'))
            ->toDateString();

        return redirect()->route('intelligence.rental-usage-anomalies.index', [
            'agency' => $anomalyResult->agency_id,
            'date_from' => $returnDate,
            'date_to' => $returnDate,
        ])->with('status', 'Vérification humaine enregistrée. Les données métier restent inchangées.');
    }
}
