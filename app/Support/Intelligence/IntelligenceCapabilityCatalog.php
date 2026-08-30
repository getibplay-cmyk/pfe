<?php

namespace App\Support\Intelligence;

use App\Enums\IntelligenceCapability;
use App\Support\Intelligence\DemandForecasting\DemandForecastRuntimeReadiness;
use App\Support\Intelligence\FleetReallocation\FleetReallocationRuntimeReadiness;
use App\Support\Intelligence\RentalUsageAnomaly\RentalUsageAnomalyRuntimeReadiness;
use App\Support\Intelligence\VehicleColor\VehicleColorRuntimeReadiness;
use App\Support\Intelligence\VehicleDamage\VehicleDamageRuntimeReadiness;
use App\Support\Intelligence\VehiclePlate\VehiclePlateRuntimeReadiness;
use LogicException;
use Throwable;

class IntelligenceCapabilityCatalog
{
    private const DEFINITIONS = [
        'demand_forecast' => [
            'label' => 'Prévision de demande',
            'usage' => 'Planification des réservations',
            'description' => 'Anticipe la demande des sept prochains jours pour aider à organiser la flotte.',
            'permission' => 'prediction.forecast.import',
        ],
        'fleet_reallocation' => [
            'label' => 'Suggestion de réallocation',
            'usage' => 'Planification de flotte',
            'description' => 'Propose des transferts inter-agences soumis à une décision humaine.',
            'permission' => 'prediction.demo.review',
        ],
        'rental_usage_anomaly' => [
            'label' => 'Usages atypiques',
            'usage' => 'Revue des locations',
            'description' => 'Priorise les locations qui méritent une vérification humaine.',
            'permission' => 'prediction.anomaly.review',
        ],
        'vehicle_color' => [
            'label' => 'Couleur suggérée',
            'usage' => 'Ajout de véhicule',
            'description' => 'Suggère une couleur depuis une photo, sans modifier automatiquement le véhicule.',
            'permission' => 'prediction.color.review',
        ],
        'vehicle_plate' => [
            'label' => 'Immatriculation détectée',
            'usage' => 'Ajout de véhicule',
            'description' => 'Propose une immatriculation à confirmer ou corriger par l’utilisateur.',
            'permission' => 'prediction.plate.review',
        ],
        'vehicle_damage' => [
            'label' => 'Analyse des dommages',
            'usage' => 'Inspection de retour',
            'description' => 'Signale des zones visibles à vérifier pendant l’inspection de retour.',
            'permission' => 'prediction.damage.review',
        ],
    ];

    /** @var array<string, bool> */
    private array $runtimeReadiness = [];

    public function __construct(
        private readonly DemandForecastRuntimeReadiness $demandForecast,
        private readonly FleetReallocationRuntimeReadiness $fleetReallocation,
        private readonly RentalUsageAnomalyRuntimeReadiness $rentalUsageAnomaly,
        private readonly VehicleColorRuntimeReadiness $vehicleColor,
        private readonly VehiclePlateRuntimeReadiness $vehiclePlate,
        private readonly VehicleDamageRuntimeReadiness $vehicleDamage,
    ) {}

    /** @return array<string, array{capability: IntelligenceCapability, label: string, usage: string, description: string}> */
    public function all(): array
    {
        $definitions = [];
        foreach (IntelligenceCapability::cases() as $capability) {
            $definition = self::DEFINITIONS[$capability->value] ?? null;
            if ($definition === null) {
                throw new LogicException('Le catalogue Intelligence est incomplet.');
            }
            $definitions[$capability->value] = ['capability' => $capability, ...$definition];
        }
        if (count($definitions) !== count(self::DEFINITIONS)) {
            throw new LogicException('Le catalogue Intelligence contient une capacité inconnue.');
        }

        return $definitions;
    }

    /** @return array{capability: IntelligenceCapability, label: string, usage: string, description: string} */
    public function definition(IntelligenceCapability $capability): array
    {
        return $this->all()[$capability->value];
    }

    public function permission(IntelligenceCapability $capability): string
    {
        return $this->definition($capability)['permission'];
    }

    public function globallyEnabled(IntelligenceCapability $capability): bool
    {
        return match ($capability) {
            IntelligenceCapability::DemandForecast => (bool) config('intelligence.demand_forecasting.runtime_enabled'),
            IntelligenceCapability::FleetReallocation => (bool) config('intelligence.fleet_reallocation.runtime_enabled'),
            IntelligenceCapability::RentalUsageAnomaly => (bool) config('intelligence.rental_usage_anomaly.enabled'),
            IntelligenceCapability::VehicleColor => (bool) config('intelligence.vehicle_color_v8.enabled'),
            IntelligenceCapability::VehiclePlate => (bool) config('intelligence.vehicle_plate_hybrid_review.enabled'),
            IntelligenceCapability::VehicleDamage => (bool) config('intelligence.vehicle_damage_v1.enabled'),
        };
    }

    public function runtimeReady(IntelligenceCapability $capability): bool
    {
        if (array_key_exists($capability->value, $this->runtimeReadiness)) {
            return $this->runtimeReadiness[$capability->value];
        }

        try {
            $ready = match ($capability) {
                IntelligenceCapability::DemandForecast => $this->demandForecast->ready(),
                IntelligenceCapability::FleetReallocation => $this->fleetReallocation->ready(),
                IntelligenceCapability::RentalUsageAnomaly => $this->rentalUsageAnomaly->ready(),
                IntelligenceCapability::VehicleColor => $this->vehicleColor->ready(),
                IntelligenceCapability::VehiclePlate => $this->vehiclePlate->ocrReady(),
                IntelligenceCapability::VehicleDamage => $this->vehicleDamage->ready(),
            };
        } catch (Throwable) {
            $ready = false;
        }

        return $this->runtimeReadiness[$capability->value] = $ready;
    }
}
