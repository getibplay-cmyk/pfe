<?php

namespace Database\Seeders;

use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Finance\RecordDepositReceipt;
use App\Actions\Rentals\AcceptRentalContract;
use App\Actions\Rentals\ActivateRentalContract;
use App\Actions\Rentals\AttachContractVersionDocument;
use App\Actions\Rentals\CalculateReturnCharges;
use App\Actions\Rentals\CompleteDepartureInspection;
use App\Actions\Rentals\CompleteReturnInspection;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Rentals\MarkContractReady;
use App\Actions\Rentals\MarkRentalReturned;
use App\Actions\Rentals\ReportVehicleDamage;
use App\Actions\Rentals\ReviewDamageResponsibility;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\DocumentType;
use App\Models\Customer;
use App\Models\RentalContract;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\PreventsDemoSeedingInProduction;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

class Lot04DemoSeeder extends Seeder
{
    use PreventsDemoSeedingInProduction;

    public function run(
        CreateVehicle $createVehicle,
        CreateReservation $createReservation,
        ConfirmReservation $confirmReservation,
        CreateRentalContractFromReservation $createContract,
        MarkContractReady $markReady,
        AcceptRentalContract $acceptContract,
        CompleteDepartureInspection $departure,
        ActivateRentalContract $activate,
        CompleteReturnInspection $returnInspection,
        CalculateReturnCharges $calculateCharges,
        ReportVehicleDamage $reportDamage,
        ReviewDamageResponsibility $reviewDamage,
        MarkRentalReturned $markReturned,
        StorePrivateDocument $storeDocument,
        RecordDepositReceipt $receiveDeposit,
        AttachContractVersionDocument $attachContractDocument,
    ): void {
        $this->ensureDemoSeedingIsAllowed();

        $tenant = Tenant::where('slug', 'atlas-location-demo')->firstOrFail();
        app(TenantContext::class)->run($tenant, function () use ($createVehicle, $createReservation, $confirmReservation, $createContract, $markReady, $acceptContract, $departure, $activate, $returnInspection, $calculateCharges, $reportDamage, $reviewDamage, $markReturned, $storeDocument, $receiveDeposit, $attachContractDocument) {
            if (RentalContract::where('contract_number', 'like', 'CTR-%')->exists()) {
                return;
            }
            $owner = User::whereHas('role', fn ($query) => $query->where('slug', 'tenant-owner'))->firstOrFail();
            $start = CarbonImmutable::now(config('reservations.display_timezone'))->addDays(30)->startOfHour();
            $category = VehicleCategory::where('is_active', true)->firstOrFail();
            $agencyId = Vehicle::where('vehicle_category_id', $category->id)->value('agency_id');
            $customer = Customer::with(['drivers' => fn ($query) => $query->where('licence_expires_at', '>', $start->addDays(20)->toDateString())])
                ->where('agency_id', $agencyId)
                ->whereHas('drivers', fn ($query) => $query->where('licence_expires_at', '>', $start->addDays(20)->toDateString()))->firstOrFail();
            $driver = $customer->drivers->first();
            $vehicles = Vehicle::where('agency_id', $agencyId)->where('vehicle_category_id', $category->id)->where('operational_status', 'active')->take(8)->get();
            while ($vehicles->count() < 8) {
                $index = $vehicles->count() + 1;
                $vehicles->push($createVehicle->handle(['agency_id' => $agencyId, 'vehicle_category_id' => $category->id, 'registration_number' => 'RF-DEMO-04-'.$index, 'brand' => 'Dacia', 'model' => 'Duster', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 2000 + ($index * 100)], $owner->id));
            }
            $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% RentFleet document contractuel fictif\n%%EOF");
            $storeDocument->handle($customer, ['document_type' => DocumentType::CustomerIdentity, 'title' => 'Identité fictive — démonstration', 'is_sensitive' => true], $pdf('identite-contrat-demo.pdf'), $owner->id);
            $storeDocument->handle($driver, ['document_type' => DocumentType::DrivingLicence, 'title' => 'Permis fictif — démonstration', 'is_sensitive' => true], $pdf('permis-contrat-demo.pdf'), $owner->id);

            $contracts = collect();
            foreach ($vehicles->take(8) as $index => $vehicle) {
                $reservation = $createReservation->handle(['agency_id' => $vehicle->agency_id, 'customer_id' => $customer->id, 'driver_id' => $driver->id, 'vehicle_category_id' => $vehicle->vehicle_category_id, 'vehicle_id' => $vehicle->id, 'starts_at' => $start->addDays($index * 3), 'ends_at' => $start->addDays($index * 3 + 1), 'status' => 'draft', 'notes' => 'Scénario fictif Lot 04'], $owner->id);
                $confirmReservation->handle($reservation, $owner->id);
                $contracts->push($createContract->handle($reservation, $owner->id));
            }

            foreach ($contracts->slice(1) as $contract) {
                $markReady->handle($contract, $owner->id);
            }
            foreach ($contracts->slice(2) as $contract) {
                $attachContractDocument->handle($contract, $pdf('contrat-version-'.$contract->id.'.pdf'), $owner->id);
                $acceptContract->handle($contract, ['accepted_by_name' => 'Client Démo', 'acceptance_method' => 'typed_name', 'ip_address' => '127.0.0.1', 'user_agent' => 'RentFleet Demo Seeder'], $owner->id);
            }
            foreach ($contracts->slice(3) as $contract) {
                $mileage = $contract->vehicle->current_mileage + 10;
                $departure->handle($contract, ['mileage' => $mileage, 'fuel_level' => '80.00', 'items' => $this->items()], $owner->id);
                if ($contract->deposit_required !== '0.00') {
                    $receiveDeposit->handle($contract, $contract->deposit_required, 'demo-departure-deposit-'.$contract->id, $owner->id);
                }
                $activate->handle($contract, $owner->id);
            }
            foreach ($contracts->slice(4) as $contract) {
                $contract = $contract->refresh();
                $returnInspection->handle($contract, ['mileage' => $contract->start_mileage + 250, 'fuel_level' => '65.00', 'items' => $this->items('damaged')], $owner->id);
                $calculateCharges->handle($contract, ['cleaning_approved' => true, 'cleaning_amount' => '120.00']);
            }

            $pending = $contracts[4]->refresh();
            $return = $pending->inspections()->where('inspection_type', 'return')->firstOrFail();
            $minor = $reportDamage->handle($pending, ['return_inspection_id' => $return->id, 'description' => 'Rayure mineure fictive', 'vehicle_area' => 'Aile avant', 'severity' => 'minor', 'estimated_cost' => '350.00'], $owner->id);
            $reviewDamage->handle($minor, ['responsibility' => 'customer', 'status' => 'resolved', 'approved_cost' => '250.00', 'reason' => 'Décision humaine de démonstration'], $owner->id);
            $major = $reportDamage->handle($pending, ['return_inspection_id' => $return->id, 'description' => 'Impact majeur fictif', 'vehicle_area' => 'Pare-chocs', 'severity' => 'major', 'estimated_cost' => '2200.00'], $owner->id);
            $reviewDamage->handle($major, ['responsibility' => 'pending', 'status' => 'under_review', 'reason' => 'Revue humaine de démonstration en cours'], $owner->id);

            foreach ($contracts->slice(5) as $returned) {
                $returned = $returned->refresh();
                $ids = $returned->charges()->where('status', 'proposed')->pluck('id')->all();
                $markReturned->handle($returned, ['approved_charge_ids' => $ids, 'reason' => 'Retour fictif validé'], $owner->id);
            }
        });
    }

    private function items(string $body = 'good'): array
    {
        return [['item_code' => 'body', 'label' => 'Carrosserie', 'condition' => $body], ['item_code' => 'interior', 'label' => 'Habitacle', 'condition' => 'good'], ['item_code' => 'tyres', 'label' => 'Pneus', 'condition' => 'good']];
    }
}
