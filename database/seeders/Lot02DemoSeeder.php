<?php

namespace Database\Seeders;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Vehicles\ChangeVehicleOperationalStatus;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\VehicleOperationalStatus;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

class Lot02DemoSeeder extends Seeder
{
    public function run(CreateVehicle $createVehicle, ChangeVehicleOperationalStatus $changeStatus, CreateCustomer $createCustomer, CreateDriver $createDriver, StorePrivateDocument $storeDocument): void
    {
        $tenant = Tenant::where('slug', 'atlas-location-demo')->firstOrFail();
        $context = app(TenantContext::class);
        $context->run($tenant, function () use ($createVehicle, $changeStatus, $createCustomer, $createDriver, $storeDocument, $tenant) {
            $agencies = Agency::orderBy('id')->get();
            $owner = User::where('tenant_id', $tenant->id)->whereHas('role', fn ($q) => $q->where('slug', 'tenant-owner'))->firstOrFail();
            $categories = collect([
                ['code' => 'ECO', 'name' => 'Economy', 'acriss_code' => 'ECMR', 'seats' => 5, 'doors' => 5],
                ['code' => 'COM', 'name' => 'Compact', 'acriss_code' => 'CDMR', 'seats' => 5, 'doors' => 5],
                ['code' => 'SUV', 'name' => 'SUV', 'acriss_code' => 'SFAR', 'seats' => 5, 'doors' => 5],
                ['code' => 'PRE', 'name' => 'Premium', 'acriss_code' => 'PDAR', 'seats' => 5, 'doors' => 5],
            ])->map(fn ($data) => VehicleCategory::create([...$data, 'is_active' => true]));

            $vehicles = collect();
            for ($i = 1; $i <= 16; $i++) {
                $vehicles->push($createVehicle->handle([
                    'agency_id' => $agencies[($i - 1) % $agencies->count()]->id,
                    'vehicle_category_id' => $categories[($i - 1) % 4]->id,
                    'registration_number' => sprintf('%05d-A-26', 10000 + $i),
                    'vin' => 'DEMO-VIN-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'brand' => ['Dacia', 'Renault', 'Hyundai', 'Peugeot'][($i - 1) % 4],
                    'model' => ['Sandero', 'Clio', 'Tucson', '3008'][($i - 1) % 4],
                    'production_year' => 2021 + ($i % 5),
                    'fuel_type' => $i % 5 === 0 ? 'hybrid' : 'diesel',
                    'transmission' => $i % 3 === 0 ? 'automatic' : 'manual',
                    'current_mileage' => 12000 + ($i * 1700),
                ], $owner->id));
            }
            $changeStatus->handle($vehicles[3], VehicleOperationalStatus::Maintenance, 'Entretien fictif', $owner->id);
            $changeStatus->handle($vehicles[7], VehicleOperationalStatus::OutOfService, 'Immobilisation fictive', $owner->id);
            $changeStatus->handle($vehicles[15], VehicleOperationalStatus::Archived, 'Archivage fictif', $owner->id);

            $customers = collect();
            for ($i = 1; $i <= 12; $i++) {
                $customer = $createCustomer->handle([
                    'agency_id' => $agencies[($i - 1) % $agencies->count()]->id,
                    'customer_type' => CustomerType::Individual,
                    'first_name' => 'Client'.$i,
                    'last_name' => 'Fictif',
                    'email' => 'client'.$i.'@example.test',
                    'identity_type' => 'demo_identity',
                    'identity_number' => 'DEMO-CIN-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'verification_status' => VerificationStatus::Verified,
                ]);
                $expiry = match ($i % 3) {
                    0 => today()->subMonth(), 1 => today()->addDays(20), default => today()->addYears(2)
                };
                $createDriver->handle($customer, ['first_name' => 'Conducteur'.$i, 'last_name' => 'Fictif', 'licence_number' => 'DEMO-PERMIS-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'licence_expires_at' => $expiry, 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);
                $customers->push($customer);
            }

            $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% RentFleet document fictif\n%%EOF");
            $storeDocument->handle($customers->first(), ['document_type' => 'customer_identity', 'title' => 'Identité fictive', 'is_sensitive' => true], $pdf('identite-demo.pdf'), $owner->id);
            $storeDocument->handle($vehicles->first(), ['document_type' => 'vehicle_registration', 'title' => 'Carte grise fictive', 'is_sensitive' => true], $pdf('carte-grise-demo.pdf'), $owner->id);
            $storeDocument->handle($vehicles->last(), ['document_type' => 'vehicle_insurance', 'title' => 'Assurance fictive', 'is_sensitive' => false], $pdf('assurance-demo.pdf'), $owner->id);
        });
    }
}
