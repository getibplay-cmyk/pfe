<?php

namespace Database\Seeders;

use App\Actions\Pricing\CreatePricingRule;
use App\Actions\Reservations\CancelReservation;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\ExpirePendingReservations;
use App\Enums\ReservationStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\PreventsDemoSeedingInProduction;
use Illuminate\Database\Seeder;

class Lot03DemoSeeder extends Seeder
{
    use PreventsDemoSeedingInProduction;

    public function run(CreatePricingRule $createPricingRule, CreateReservation $createReservation, ConfirmReservation $confirmReservation, CancelReservation $cancelReservation, ExpirePendingReservations $expirePending): void
    {
        $this->ensureDemoSeedingIsAllowed();

        $tenant = Tenant::where('slug', 'atlas-location-demo')->firstOrFail();
        app(TenantContext::class)->run($tenant, function () use ($tenant, $createPricingRule, $createReservation, $confirmReservation, $cancelReservation) {
            $owner = User::where('tenant_id', $tenant->id)->whereHas('role', fn ($query) => $query->where('slug', 'tenant-owner'))->firstOrFail();
            $agencies = Agency::orderBy('id')->get();
            $categories = VehicleCategory::orderBy('id')->get();
            foreach ($categories as $index => $category) {
                $createPricingRule->handle([
                    'agency_id' => null, 'vehicle_category_id' => $category->id, 'name' => 'Tarif standard '.$category->name,
                    'daily_rate' => (string) (350 + ($index * 150)).'.00', 'deposit_amount' => '3000.00', 'included_km_per_day' => 200,
                    'extra_km_rate' => '2.50', 'late_hour_rate' => '75.00', 'minimum_days' => 1, 'maximum_days' => 30,
                    'valid_from' => today()->subYear()->toDateString(), 'valid_to' => null, 'priority' => 0, 'currency' => 'MAD', 'conditions' => ['demo' => true], 'is_active' => true,
                ], $owner->id);
            }
            $createPricingRule->handle([
                'agency_id' => $agencies->first()->id, 'vehicle_category_id' => $categories->first()->id, 'name' => 'Tarif agence Casablanca',
                'daily_rate' => '325.00', 'deposit_amount' => '2500.00', 'included_km_per_day' => 220, 'extra_km_rate' => '2.25', 'late_hour_rate' => '70.00',
                'minimum_days' => 1, 'maximum_days' => 30, 'valid_from' => today()->subMonth()->toDateString(), 'valid_to' => null, 'priority' => 10, 'currency' => 'MAD', 'conditions' => ['demo' => true], 'is_active' => true,
            ], $owner->id);

            $start = CarbonImmutable::now(config('reservations.display_timezone'))->addDays(3)->startOfHour();
            $customers = Customer::with('drivers')->whereHas('drivers', fn ($query) => $query->where('licence_expires_at', '>', $start->toDateString()))->take(5)->get();
            $vehicles = Vehicle::where('operational_status', 'active')->orderBy('id')->get();
            $make = function (int $index, ReservationStatus $status, CarbonImmutable $startsAt, ?CarbonImmutable $expiresAt = null) use ($customers, $vehicles, $createReservation, $owner) {
                $vehicle = $vehicles[$index];
                $endsAt = $startsAt->addDays(2);
                $customer = $customers->first(fn ($candidate) => (int) $candidate->agency_id === (int) $vehicle->agency_id
                    && $candidate->drivers->contains(fn ($driver) => $driver->licence_expires_at->endOfDay()->gte($endsAt)));
                $driver = $customer?->drivers->first(fn ($candidate) => $candidate->licence_expires_at->endOfDay()->gte($endsAt));
                if (! $customer || ! $driver) {
                    throw new \RuntimeException('Aucun client de démonstration compatible avec l’agence et la période.');
                }
                $reservation = $createReservation->handle([
                    'agency_id' => $vehicle->agency_id, 'customer_id' => $customer->id, 'driver_id' => $driver->id,
                    'vehicle_category_id' => $vehicle->vehicle_category_id, 'vehicle_id' => $vehicle->id,
                    'starts_at' => $startsAt, 'ends_at' => $endsAt, 'status' => $status->value,
                    'expires_at' => $expiresAt, 'notes' => 'Donnée de démonstration fictive',
                ], $owner->id);

                return $reservation;
            };

            $make(0, ReservationStatus::Draft, $start->addDays(20));
            $make(1, ReservationStatus::Pending, $start->addDays(15), now()->addHour()->toImmutable());
            $confirmed = $make(2, ReservationStatus::Draft, $start);
            $confirmReservation->handle($confirmed, $owner->id);
            $cancelled = $make(3, ReservationStatus::Draft, $start->addDays(7));
            $confirmReservation->handle($cancelled, $owner->id);
            $cancelReservation->handle($cancelled, 'Annulation fictive de démonstration', $owner->id);
            $make(4, ReservationStatus::Pending, $start->addDays(10), now()->subMinute()->toImmutable());
        });

        $expirePending->handle();
    }
}
