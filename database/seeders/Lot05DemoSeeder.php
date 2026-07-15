<?php

namespace Database\Seeders;

use App\Actions\Finance\AllocatePaymentToInvoice;
use App\Actions\Finance\CloseRentalContract;
use App\Actions\Finance\CreateInvoiceFromReturnedContract;
use App\Actions\Finance\IssueInvoice;
use App\Actions\Finance\PostPayment;
use App\Actions\Finance\RecordDepositReceipt;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\RefundDeposit;
use App\Actions\Finance\RetainDeposit;
use App\Actions\Insurance\CreateInsuranceClaim;
use App\Actions\Insurance\StartInsuranceClaimReview;
use App\Actions\Maintenance\ApproveMaintenanceOrder;
use App\Actions\Maintenance\CreateMaintenanceOrder;
use App\Actions\Maintenance\StartMaintenanceOrder;
use App\Enums\RentalContractStatus;
use App\Models\InsuranceCompany;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use App\Models\RentalContract;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\PreventsDemoSeedingInProduction;
use Illuminate\Database\Seeder;

class Lot05DemoSeeder extends Seeder
{
    use PreventsDemoSeedingInProduction;

    public function run(
        CreateInvoiceFromReturnedContract $createInvoice,
        IssueInvoice $issueInvoice,
        RecordPayment $recordPayment,
        AllocatePaymentToInvoice $allocate,
        PostPayment $postPayment,
        RecordDepositReceipt $receiveDeposit,
        RetainDeposit $retainDeposit,
        RefundDeposit $refundDeposit,
        CloseRentalContract $closeContract,
        CreateMaintenanceOrder $createMaintenance,
        ApproveMaintenanceOrder $approveMaintenance,
        StartMaintenanceOrder $startMaintenance,
        CreateInsuranceClaim $createClaim,
        StartInsuranceClaimReview $startClaimReview,
    ): void {
        $this->ensureDemoSeedingIsAllowed();

        $tenant = Tenant::where('slug', 'atlas-location-demo')->firstOrFail();
        app(TenantContext::class)->run($tenant, function () use ($createInvoice, $issueInvoice, $recordPayment, $allocate, $postPayment, $receiveDeposit, $retainDeposit, $refundDeposit, $closeContract, $createMaintenance, $approveMaintenance, $startMaintenance, $createClaim, $startClaimReview) {
            if (Invoice::exists()) {
                return;
            }
            $owner = User::whereHas('role', fn ($query) => $query->where('slug', 'tenant-owner'))->firstOrFail();
            $contracts = RentalContract::orderBy('id')->take(2)->get();
            foreach ($contracts as $contract) {
                $contract->forceFill(['status' => RentalContractStatus::Returned, 'returned_at' => now(), 'actual_return_at' => now()])->save();
                $contract->vehicleBlock?->forceFill(['status' => 'released', 'released_at' => now()])->save();
            }

            $partial = $issueInvoice->handle($createInvoice->handle($contracts[0], $owner->id), $owner->id);
            $partialPayment = $recordPayment->handle($this->paymentData($contracts[0], '100.00', 'demo-partial'), $owner->id);
            $allocate->handle($partialPayment, $partial, '100.00');
            $postPayment->handle($partialPayment, $owner->id);
            $receiveDeposit->handle($contracts[0], '300.00', 'demo-deposit-retained', $owner->id);
            $retainDeposit->handle($contracts[0], '50.00', 'demo-deposit-retention', 'Retenue fictive décidée humainement', $owner->id);

            $paid = $issueInvoice->handle($createInvoice->handle($contracts[1], $owner->id), $owner->id);
            $fullPayment = $recordPayment->handle($this->paymentData($contracts[1], $paid->total_amount, 'demo-paid'), $owner->id);
            $allocate->handle($fullPayment, $paid, $paid->total_amount);
            $postPayment->handle($fullPayment, $owner->id);
            $receiveDeposit->handle($contracts[1], '300.00', 'demo-deposit-refund', $owner->id);
            $refundDeposit->handle($contracts[1], '300.00', 'demo-deposit-refunded', $owner->id);
            $closeContract->handle($contracts[1], $owner->id);

            $vehicles = Vehicle::take(2)->get();
            $base = CarbonImmutable::now()->addYear()->startOfDay();
            $createMaintenance->handle(['agency_id' => $vehicles[0]->agency_id, 'vehicle_id' => $vehicles[0]->id, 'maintenance_type' => 'preventive', 'priority' => 'normal', 'title' => 'Révision annuelle fictive', 'scheduled_start_at' => $base, 'scheduled_end_at' => $base->addHours(3), 'estimated_cost' => '900.00'], $owner->id);
            $progress = $createMaintenance->handle(['agency_id' => $vehicles[1]->agency_id, 'vehicle_id' => $vehicles[1]->id, 'maintenance_type' => 'corrective', 'priority' => 'high', 'title' => 'Réparation fictive', 'scheduled_start_at' => $base->addDay(), 'scheduled_end_at' => $base->addDay()->addHours(4), 'estimated_cost' => '1500.00'], $owner->id);
            $approveMaintenance->handle($progress, $owner->id);
            $startMaintenance->handle($progress, $owner->id);

            $company = InsuranceCompany::create(['name' => 'Atlas Assurance Démo', 'email' => 'demo@assurance.test', 'is_active' => true]);
            $policy = new InsurancePolicy(['agency_id' => $vehicles[0]->agency_id, 'vehicle_id' => $vehicles[0]->id, 'insurance_company_id' => $company->id, 'policy_type' => 'comprehensive', 'starts_at' => today()->subYear(), 'ends_at' => today()->addDays(20), 'premium_amount' => '4800.00', 'deductible_amount' => '2500.00', 'currency' => 'MAD', 'status' => 'active']);
            $policy->setPolicyNumber('DEMO-POLICY-0001')->save();
            $policy->coverages()->create(['coverage_type' => 'collision', 'label' => 'Collision fictive', 'limit_amount' => '50000.00', 'deductible_amount' => '2500.00', 'terms' => ['demo' => true]]);
            $claim = $createClaim->handle(['agency_id' => $policy->agency_id, 'insurance_policy_id' => $policy->id, 'reported_at' => now(), 'claimed_amount' => '5000.00', 'notes' => 'Scénario fictif, décision humaine en attente.'], $owner->id);
            $startClaimReview->handle($claim, $owner->id, 'Revue humaine de démonstration en cours.');
        });
    }

    private function paymentData(RentalContract $contract, string $amount, string $key): array
    {
        return ['agency_id' => $contract->agency_id, 'rental_contract_id' => $contract->id, 'customer_id' => $contract->customer_id, 'payment_method' => 'cash', 'amount' => $amount, 'currency' => $contract->currency, 'idempotency_key' => $key];
    }
}
