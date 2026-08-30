<?php

namespace App\Enums;

enum IntelligenceCapability: string
{
    case DemandForecast = 'demand_forecast';
    case FleetReallocation = 'fleet_reallocation';
    case RentalUsageAnomaly = 'rental_usage_anomaly';
    case VehicleColor = 'vehicle_color';
    case VehiclePlate = 'vehicle_plate';
    case VehicleDamage = 'vehicle_damage';
}
