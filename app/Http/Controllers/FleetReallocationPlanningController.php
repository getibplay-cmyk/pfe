<?php

namespace App\Http\Controllers;

use App\Actions\Fleet\QueueOperationalFleetReallocationPlan;
use App\Enums\FleetReallocationPlanningRunStatus;
use App\Enums\IntelligenceCapability;
use App\Exceptions\FleetReallocationPlanningException;
use App\Models\Agency;
use App\Models\DemandForecastExecutionRun;
use App\Models\FleetReallocationPlanningRun;
use App\Support\Intelligence\FleetReallocation\FleetReallocationReadiness;
use App\Support\Intelligence\TenantIntelligenceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FleetReallocationPlanningController extends Controller
{
    public function index(
        FleetReallocationReadiness $readiness,
        TenantIntelligenceAccess $intelligenceAccess,
    ): View {
        $this->authorize('viewAny', FleetReallocationPlanningRun::class);
        $agencies = Agency::query()->where('is_active', true)->orderBy('id')->get();
        $availability = $intelligenceAccess->status(IntelligenceCapability::FleetReallocation);
        $result = $availability->usable() ? $readiness->evaluate($agencies) : null;
        $ready = $result?->ready() ?? false;
        $referenceDate = null;
        if ($ready && $agencies->isNotEmpty()) {
            $referenceDate = DemandForecastExecutionRun::query()
                ->with('forecastRun')
                ->where('agency_id', $agencies->first()->getKey())
                ->where('status', 'succeeded')
                ->latest('finished_at')
                ->latest('id')
                ->first()?->forecastRun?->as_of_date?->toDateString();
        }

        return view('fleet.reallocation-planning.index', [
            'assistant' => [
                'ready' => $ready,
                'readinessMessage' => $ready
                    ? 'Les prévisions, disponibilités, distances et le moteur de calcul sont prêts.'
                    : ($availability->tenantAuthorized
                        ? 'Le plan ne peut pas être calculé tant que ses données nécessaires ne sont pas complètes.'
                        : 'Cette assistance n’est pas disponible pour votre entreprise.'),
                'referenceDate' => $referenceDate,
                'storeUrl' => route('fleet.reallocation-planning.runs.store'),
                'pollDelay' => 1500,
                'maxPollAttempts' => 120,
            ],
        ]);
    }

    public function store(
        Request $request,
        QueueOperationalFleetReallocationPlan $queue,
    ): JsonResponse {
        $this->authorize('create', FleetReallocationPlanningRun::class);
        abort_if($request->all() !== [], 422, 'Aucune donnée de calcul ne doit être transmise.');

        try {
            $run = $queue->handle($request->user());
        } catch (FleetReallocationPlanningException) {
            return response()->json([
                'message' => 'Le plan ne peut pas être calculé avec les données disponibles.',
            ], 422);
        }

        return response()->json([
            'run_id' => $run->run_id,
            'status' => $run->status->value,
            'status_url' => route('fleet.reallocation-planning.runs.status', $run),
        ], 202);
    }

    public function status(FleetReallocationPlanningRun $run): JsonResponse
    {
        $this->authorize('view', $run);
        $snapshot = $run->snapshot;
        $names = collect($snapshot['agencies'] ?? [])->mapWithKeys(
            fn (array $agency): array => [(int) $agency['agency_id'] => (string) $agency['name']],
        );
        $agencies = [];
        foreach ($snapshot['days'] ?? [] as $day) {
            foreach ($day['nodes'] ?? [] as $node) {
                $agencies[] = [
                    'name' => $names->get((int) $node['agency_id'], 'Agence'),
                    'date' => $day['date'],
                    'available_vehicle_units' => (int) $node['available_vehicle_units'],
                    'predicted_departures' => (string) $node['conditional_mean'],
                    'planning_vehicle_units' => (int) $node['planning_vehicle_units'],
                    'transferable_surplus' => (int) $node['transferable_surplus'],
                    'uncovered_need' => (int) $node['uncovered_need'],
                ];
            }
        }

        $recommendations = [];
        if ($run->status === FleetReallocationPlanningRunStatus::Succeeded) {
            $run->loadMissing('recommendations');
            foreach ($run->recommendations as $recommendation) {
                $recommendations[] = [
                    'date' => $recommendation->planning_date->toDateString(),
                    'from_agency' => $names->get($recommendation->from_agency_id, 'Agence'),
                    'to_agency' => $names->get($recommendation->to_agency_id, 'Agence'),
                    'vehicle_units' => $recommendation->vehicle_units,
                    'distance_km' => (string) $recommendation->distance_km,
                ];
            }
        }

        return response()->json([
            'status' => $run->status->value,
            'reference_date' => $run->reference_date->toDateString(),
            'generated_at' => $run->status === FleetReallocationPlanningRunStatus::Succeeded
                ? $run->finished_at?->utc()->format('Y-m-d\TH:i:s\Z')
                : null,
            'outcome' => $run->outcome,
            'agencies' => $agencies,
            'recommendations' => $recommendations,
            'message' => $this->message($run),
        ]);
    }

    private function message(FleetReallocationPlanningRun $run): string
    {
        if ($run->status === FleetReallocationPlanningRunStatus::Queued
            || $run->status === FleetReallocationPlanningRunStatus::Running) {
            return 'Calcul du plan en cours…';
        }
        if ($run->status === FleetReallocationPlanningRunStatus::Failed) {
            return 'Le plan n’a pas pu être calculé. Les données métier restent inchangées.';
        }

        return match ($run->outcome) {
            'transfers_recommended' => 'Des transferts sont proposés pour réduire les besoins non couverts.',
            'balanced_without_transfer' => 'Aucun transfert nécessaire.',
            'insufficient_transferable_surplus' => 'Aucun véhicule transférable malgré un besoin non couvert.',
            default => 'Le plan est terminé sans action automatique.',
        };
    }
}
