<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\CreateDemandHistoryExport;
use App\Actions\Intelligence\DownloadDemandHistorySnapshot;
use App\Actions\Intelligence\ImportDemandForecastBatch;
use App\Actions\Intelligence\QueueDemandForecastExecution;
use App\Exceptions\DemandForecastValidationException;
use App\Http\Requests\DemandHistoryExportRequest;
use App\Http\Requests\ImportDemandForecastRequest;
use App\Http\Requests\QueueDemandForecastExecutionRequest;
use App\Models\Agency;
use App\Models\DemandForecastRun;
use App\Models\DemandHistoryExportRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\DemandForecasting\DemandForecastModelArtifact;
use App\Support\Intelligence\DemandForecasting\DemandForecastRuntimeReadiness;
use App\Support\Intelligence\IntelligencePseudonymizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DemandForecastController extends Controller
{
    public function index(
        Request $request,
        TenantContext $context,
        DemandForecastModelArtifact $modelArtifact,
        DemandForecastRuntimeReadiness $runtimeReadiness,
    ): View {
        $this->authorize('viewAny', DemandForecastRun::class);

        $agencies = Agency::query()
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->whereKey($agencyId))
            ->orderBy('name')
            ->get();
        $historyRuns = DemandHistoryExportRun::query()
            ->with([
                'agency',
                'executionRuns' => fn ($query) => $query
                    ->latest('requested_at')
                    ->latest('id'),
            ])
            ->withCount('forecastRuns')
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->latest('created_at')
            ->latest('id')
            ->limit(20)
            ->get();
        $forecastRuns = DemandForecastRun::query()
            ->with(['agency', 'historyExport', 'forecasts', 'executionRun'])
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->latest('as_of_date')
            ->latest('id')
            ->paginate(10);

        $artifactReady = $modelArtifact->configuredIsValid();
        $runtimeReady = $runtimeReadiness->ready();

        return view('intelligence.demand-forecasts.index', [
            'agencies' => $agencies,
            'historyRuns' => $historyRuns,
            'forecastRuns' => $forecastRuns,
            'configured' => app(IntelligencePseudonymizer::class)->configured(),
            'runtime' => [
                'enabled' => (bool) config('intelligence.demand_forecasting.runtime_enabled'),
                'artifact_ready' => $artifactReady,
                'ready' => $runtimeReady,
            ],
            'contract' => [
                'model_name' => DemandForecastContract::MODEL_NAME,
                'model_version' => DemandForecastContract::MODEL_VERSION,
                'public_wape' => DemandForecastContract::PUBLIC_WAPE,
                'public_mase' => DemandForecastContract::PUBLIC_MASE,
                'public_interval_coverage' => DemandForecastContract::PUBLIC_INTERVAL_COVERAGE,
                'distance_unit' => DemandForecastContract::DISTANCE_UNIT,
                'minimum_history_days' => DemandForecastContract::MINIMUM_HISTORY_DAYS,
                'compute' => 'CPU',
            ],
            'filters' => [
                'agency_id' => $request->integer('agency_id') ?: $context->agencyId(),
                'date_from' => $request->string('date_from')->toString() ?: today()->subDays(179)->toDateString(),
                'date_to' => $request->string('date_to')->toString() ?: today()->toDateString(),
            ],
        ]);
    }

    public function queueExecution(
        QueueDemandForecastExecutionRequest $request,
        DemandHistoryExportRun $historyRun,
        QueueDemandForecastExecution $queue,
    ): RedirectResponse {
        $run = $queue->handle($historyRun, $request->user());

        return redirect()->route('intelligence.demand-forecasts.index')->with(
            'status',
            'Inférence HGB '.$run->run_id.' ajoutée à la queue Intelligence.',
        );
    }

    public function export(
        DemandHistoryExportRequest $request,
        IntelligencePseudonymizer $pseudonymizer,
        CreateDemandHistoryExport $create,
        DownloadDemandHistorySnapshot $download,
    ): StreamedResponse {
        abort_unless($pseudonymizer->configured(), 503, 'Export de demande temporairement indisponible.');
        $data = $request->validated();
        $run = $create->handle(
            (int) $data['agency_id'],
            $data['date_from'],
            $data['date_to'],
            $request->user(),
        );

        return $download->handle($run);
    }

    public function download(
        DemandHistoryExportRun $historyRun,
        DownloadDemandHistorySnapshot $download,
    ): StreamedResponse {
        $this->authorize('view', $historyRun);

        return $download->handle($historyRun);
    }

    /** @throws JsonException */
    public function manifest(
        DemandHistoryExportRun $historyRun,
        AuditRecorder $audit,
    ): StreamedResponse {
        $this->authorize('view', $historyRun);
        $manifest = json_encode(
            $historyRun->manifest(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";

        $audit->record('prediction.demand_history.manifest_downloaded', $historyRun, [], [
            'run_id' => $historyRun->run_id,
            'manifest_version' => $historyRun->manifest_version,
            'row_count' => $historyRun->row_count,
            'distance_unit' => $historyRun->distance_unit,
            'operational_effect' => $historyRun->operational_effect,
        ]);

        return response()->streamDownload(
            static function () use ($manifest): void {
                echo $manifest;
            },
            'rentfleet_demand_manifest_'.$historyRun->run_id.'.json',
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function store(
        ImportDemandForecastRequest $request,
        DemandHistoryExportRun $historyRun,
        ImportDemandForecastBatch $import,
    ): RedirectResponse {
        $file = $request->file('forecast_batch');
        abort_unless($file instanceof UploadedFile, 422);

        try {
            $result = $import->handle($historyRun, $file, $request->user());
        } catch (DemandForecastValidationException $exception) {
            throw ValidationException::withMessages([
                'forecast_batch' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('intelligence.demand-forecasts.index')->with(
            'status',
            $result->created
                ? 'Prévisions D+1 à D+7 validées et importées en mode consultatif.'
                : 'Prévisions déjà présentes : rejeu idempotent sans duplication.',
        );
    }
}
