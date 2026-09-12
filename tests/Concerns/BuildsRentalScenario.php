<?php

namespace Tests\Concerns;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Rentals\AcceptRentalContract;
use App\Actions\Rentals\AttachContractVersionDocument;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Rentals\MarkContractReady;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\RentalContract;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

trait BuildsRentalScenario
{
    protected function scenario(string $role = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create(['settings' => ['currency' => 'MAD', 'timezone' => 'Africa/Casablanca']]);
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $role === 'tenant-owner' ? null : $agency->id, 'role_id' => Role::where('slug', $role)->value('id'), 'must_change_password' => false]);

        return app(TenantContext::class)->run($tenant, function () use ($tenant, $agency, $user) {
            $category = VehicleCategory::create(['code' => 'C-'.str()->random(8), 'name' => 'Catégorie test', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle(['agency_id' => $agency->id, 'vehicle_category_id' => $category->id, 'registration_number' => 'TEST-'.str()->random(8), 'brand' => 'Dacia', 'model' => 'Duster', 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000], $user->id);
            $customer = app(CreateCustomer::class)->handle(['agency_id' => $agency->id, 'customer_type' => 'individual', 'first_name' => 'Client', 'last_name' => 'Test', 'verification_status' => VerificationStatus::Verified]);
            $driver = app(CreateDriver::class)->handle($customer, ['first_name' => 'Conducteur', 'last_name' => 'Test', 'licence_number' => 'FICTIF-'.str()->random(8), 'licence_expires_at' => today()->addYears(2), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);
            $pricing = PricingRule::create(['agency_id' => null, 'vehicle_category_id' => $category->id, 'name' => 'Tarif test', 'daily_rate' => '400.00', 'deposit_amount' => '0.00', 'included_km_per_day' => 200, 'extra_km_rate' => '2.50', 'late_hour_rate' => '75.00', 'minimum_days' => 1, 'maximum_days' => 30, 'valid_from' => today()->subYear(), 'priority' => 0, 'currency' => 'MAD', 'conditions' => [], 'is_active' => true, 'created_by' => $user->id]);
            $start = CarbonImmutable::now()->addDays(3)->startOfHour();
            $reservation = app(CreateReservation::class)->handle(['agency_id' => $agency->id, 'customer_id' => $customer->id, 'driver_id' => $driver->id, 'vehicle_category_id' => $category->id, 'vehicle_id' => $vehicle->id, 'starts_at' => $start, 'ends_at' => $start->addDay(), 'status' => 'draft'], $user->id);
            $reservation = app(ConfirmReservation::class)->handle($reservation, $user->id);

            return compact('tenant', 'agency', 'user', 'category', 'vehicle', 'customer', 'driver', 'pricing', 'reservation');
        });
    }

    protected function readyScenarioContract(array $f): RentalContract
    {
        return $this->within($f, function () use ($f) {
            $pdf = fn () => UploadedFile::fake()->createWithContent('document-fictif.pdf', "%PDF-1.4\nDocument de test\n%%EOF");
            app(StorePrivateDocument::class)->handle($f['customer'], ['document_type' => 'customer_identity', 'title' => 'Document fictif', 'is_sensitive' => true], $pdf(), $f['user']->id);
            app(StorePrivateDocument::class)->handle($f['driver'], ['document_type' => 'driving_licence', 'title' => 'Permis fictif', 'is_sensitive' => true], $pdf(), $f['user']->id);
            $contract = app(CreateRentalContractFromReservation::class)->handle($f['reservation'], $f['user']->id);
            $contract = app(MarkContractReady::class)->handle($contract, $f['user']->id);
            app(AttachContractVersionDocument::class)->handle($contract, $pdf(), $f['user']->id);

            return $contract->refresh();
        });
    }

    protected function acceptedScenarioContract(array $f): RentalContract
    {
        $contract = $this->readyScenarioContract($f);

        return $this->within($f, fn () => app(AcceptRentalContract::class)->handle($contract, ['accepted_by_name' => 'Client Test', 'acceptance_method' => 'typed_name', 'ip_address' => '127.0.0.1', 'user_agent' => 'Test'], $f['user']->id));
    }

    protected function within(array $f, callable $callback): mixed
    {
        return app(TenantContext::class)->run($f['tenant'], $callback, $f['user']->agency_id);
    }
}
