<?php

namespace App\Enums;

enum J11AdvisoryModule: string
{
    case DemandForecast = 'demand_forecast';
    case FleetOptimization = 'fleet_optimization';
    case PredictiveMaintenance = 'predictive_maintenance';
    case RentalUsageAnomaly = 'rental_usage_anomaly';

    public function contractId(): string
    {
        return 'rentfleet.'.$this->value.'.synthetic_advisory_record';
    }

    public function fixtureFile(): string
    {
        return match ($this) {
            self::DemandForecast => 'demand_forecast.accepted.json',
            self::FleetOptimization => 'fleet_optimization.rejected.json',
            self::PredictiveMaintenance => 'predictive_maintenance.pending.json',
            self::RentalUsageAnomaly => 'rental_usage_anomaly.accepted.json',
        };
    }

    public function fixtureSha256(): string
    {
        return match ($this) {
            self::DemandForecast => 'a1cd6ea351aa5c6b2fbe9ed93f42a4e25d5a0cf8d62376a860aa8be3cdfcd6a0',
            self::FleetOptimization => 'c7358c99f7e938b47f353abdc2c7cafd8f0e49b6eaaf6170e6b816234e99db89',
            self::PredictiveMaintenance => 'de3380708b13b914f3ebda6df8689efa67b86bfcdd025653ea10110e9dd722eb',
            self::RentalUsageAnomaly => '5d1d30002307ce7c85636e724c64906a2578e05abf3763181053383c10e5fabc',
        };
    }

    public function schemaFile(): string
    {
        return $this->value.'.v1.schema.json';
    }

    public function schemaSha256(): string
    {
        return match ($this) {
            self::DemandForecast => '4c065d70207885f2588c1e0b11f705c26303b0a1c7112b83730ebf1db496642f',
            self::FleetOptimization => '516d33f7589fbf9ae7c7c0e591d0baa0ddd5414eaa1fce532dc7eff43af8dd48',
            self::PredictiveMaintenance => 'b514d26224a3fb6ef593daca444eba1696389f438da9d97bb1f7416459e14fc0',
            self::RentalUsageAnomaly => 'bcfb4ab224ebd98d77ad1fb73cc216e49d5795536eaf3815e9031625272d055b',
        };
    }

    public function gateDecision(): string
    {
        return match ($this) {
            self::DemandForecast => 'CONFIRMED_FOR_OPTIMIZER_BENCHMARK',
            self::FleetOptimization => 'OPTIMIZER_CONDITIONAL_GATE_NOT_PASSED_NO_RETUNING',
            self::PredictiveMaintenance => 'RESEARCH_GATE_NOT_PASSED_NO_RETUNING',
            self::RentalUsageAnomaly => 'RESEARCH_GATE_PASSED_PUBLIC_PROXY_NOT_FOR_SAAS',
        };
    }

    public function auditScore(): string
    {
        return match ($this) {
            self::DemandForecast => '15/15',
            self::FleetOptimization => '12/12',
            self::PredictiveMaintenance => '13/14',
            self::RentalUsageAnomaly => '16/16',
        };
    }

    public function featureKey(): string
    {
        return 'ai.'.$this->value.'.enabled';
    }

    public function label(): string
    {
        return match ($this) {
            self::DemandForecast => 'Prévision de la demande',
            self::FleetOptimization => 'Optimisation de flotte',
            self::PredictiveMaintenance => 'Maintenance prédictive',
            self::RentalUsageAnomaly => 'Usages atypiques',
        };
    }
}
