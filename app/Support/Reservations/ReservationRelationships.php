<?php

namespace App\Support\Reservations;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Validation\ValidationException;

class ReservationRelationships
{
    public function __construct(private readonly AgencyAccess $agencies) {}

    public function resolve(array $data): array
    {
        $agencyId = $this->agencies->required($data['agency_id'] ?? null);

        $customer = Customer::whereKey($data['customer_id'] ?? null)
            ->where('agency_id', $agencyId)
            ->first();
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Le client doit appartenir à l’agence de la réservation.']);
        }

        $category = VehicleCategory::whereKey($data['vehicle_category_id'] ?? null)->first();
        if (! $category) {
            throw ValidationException::withMessages(['vehicle_category_id' => 'La catégorie est introuvable dans le tenant actif.']);
        }

        $driver = null;
        if (! empty($data['driver_id'])) {
            $driver = Driver::whereKey($data['driver_id'])->where('customer_id', $customer->id)->first();
            if (! $driver) {
                throw ValidationException::withMessages(['driver_id' => 'Le conducteur doit appartenir au client sélectionné.']);
            }
        }

        $vehicle = null;
        if (! empty($data['vehicle_id'])) {
            $vehicle = Vehicle::whereKey($data['vehicle_id'])
                ->where('agency_id', $agencyId)
                ->where('vehicle_category_id', $category->id)
                ->first();
            if (! $vehicle) {
                throw ValidationException::withMessages(['vehicle_id' => 'Le véhicule doit appartenir à cette agence et à la catégorie sélectionnée.']);
            }
        }

        return compact('agencyId', 'customer', 'category', 'driver', 'vehicle');
    }
}
