<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\QueueReservationDemandForecast;
use App\Enums\DemandForecastExecutionStatus;
use App\Exceptions\DemandForecastRuntimeUnavailableException;
use App\Http\Requests\StoreReservationDemandForecastRequest;
use App\Models\DemandForecastExecutionRun;
use App\Models\DemandForecastRun;
use App\Models\Reservation;
use App\Support\Intelligence\DemandForecasting\DemandForecastPlanningPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class ReservationDemandForecastController extends Controller
{
    public function store(
        StoreReservationDemandForecastRequest $request,
        QueueReservationDemandForecast $queue,
    ): JsonResponse {
        try {
            $run = $queue->handle(
                (int) $request->validated('agency_id'),
                $request->user(),
            );
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (DemandForecastRuntimeUnavailableException) {
            return $this->unavailable();
        } catch (Throwable $exception) {
            try {
                report(new RuntimeException(
                    'RESERVATION_DEMAND_FORECAST_QUEUE_FAILED ['.$exception::class.']',
                ));
            } catch (Throwable) {
                // La réponse reste assainie si le signalement secondaire échoue.
            }

            return $this->unavailable();
        }

        return response()->json([
            'run_id' => $run->run_id,
            'status' => $run->status->value,
            'status_url' => route('reservations.demand-forecast.show', $run),
        ], 202);
    }

    public function show(
        Request $request,
        DemandForecastExecutionRun $forecastExecution,
        DemandForecastPlanningPresenter $presenter,
    ): JsonResponse {
        $this->assertAccess($request, $forecastExecution);
        $run = $this->expireStaleRun($forecastExecution);

        if (in_array($run->status, [
            DemandForecastExecutionStatus::Queued,
            DemandForecastExecutionStatus::Running,
        ], true)) {
            return response()->json($presenter->state(
                $run,
                'Préparation des prévisions en cours…',
            ));
        }
        if ($run->status === DemandForecastExecutionStatus::Failed) {
            return response()->json($presenter->state(
                $run,
                'Les prévisions ne sont pas disponibles. Le planning reste utilisable.',
            ));
        }

        try {
            return response()->json($presenter->succeeded($run));
        } catch (UnexpectedValueException) {
            return response()->json([
                'status' => DemandForecastExecutionStatus::Failed->value,
                'generated_at' => null,
                'scope' => ['agency' => (string) $run->agency()->value('name')],
                'forecasts' => [],
                'message' => 'Les prévisions ne sont pas disponibles. Le planning reste utilisable.',
            ], 422);
        }
    }

    private function assertAccess(
        Request $request,
        DemandForecastExecutionRun $run,
    ): void {
        $this->authorize('viewAny', Reservation::class);
        $this->authorize('viewAny', DemandForecastRun::class);
        $user = $request->user();
        abort_unless(
            $user !== null
                && $user->tenant_id === $run->tenant_id
                && ($user->agency_id === null || $user->agency_id === $run->agency_id),
            404,
        );
    }

    private function expireStaleRun(
        DemandForecastExecutionRun $run,
    ): DemandForecastExecutionRun {
        if (! in_array($run->status, [
            DemandForecastExecutionStatus::Queued,
            DemandForecastExecutionStatus::Running,
        ], true)) {
            return $run;
        }

        $staleAfter = (int) config(
            'intelligence.demand_forecasting.runtime_stale_after_seconds',
        );
        $reference = $run->started_at ?? $run->requested_at;
        if ($staleAfter < 60
            || $reference === null
            || $reference->addSeconds($staleAfter)->isFuture()) {
            return $run;
        }

        DB::table('demand_forecast_execution_runs')
            ->where('tenant_id', $run->tenant_id)
            ->where('id', $run->id)
            ->whereIn('status', [
                DemandForecastExecutionStatus::Queued->value,
                DemandForecastExecutionStatus::Running->value,
            ])->update([
                'status' => DemandForecastExecutionStatus::Failed->value,
                'failure_code' => 'RUN_STALE_RECOVERED',
                'started_at' => $run->started_at ?? $run->requested_at,
                'finished_at' => now(),
            ]);

        return $run->refresh();
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'Le service de prévision est momentanément indisponible. Le planning reste utilisable.',
        ], 503);
    }
}
