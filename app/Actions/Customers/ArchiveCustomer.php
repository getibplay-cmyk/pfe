<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\InsuranceClaim;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Finance\DepositLedger;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArchiveCustomer
{
    public function __construct(
        private readonly AgencyAccess $agencyAccess,
        private readonly DepositLedger $deposits,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $locked = Customer::whereKey($customer)->lockForUpdate()->firstOrFail();
            $this->agencyAccess->required($locked->agency_id);

            if ($locked->reservations()->whereIn('status', ['draft', 'pending', 'confirmed'])->exists()) {
                $this->blocked('Le client possède une réservation active.');
            }
            if ($locked->rentalContracts()->whereNotIn('status', ['closed', 'cancelled'])->exists()) {
                $this->blocked('Le client possède un contrat non terminal.');
            }
            if (DB::table('invoices')->where('tenant_id', $locked->tenant_id)->where('customer_id', $locked->id)
                ->where(fn ($query) => $query->whereNotIn('status', ['paid', 'void'])->orWhere('balance_due', '>', 0))->exists()) {
                $this->blocked('Le client possède une facture non soldée.');
            }
            if (DB::table('payments')->where('tenant_id', $locked->tenant_id)->where('customer_id', $locked->id)->where('status', 'pending')->exists()) {
                $this->blocked('Le client possède un paiement en attente.');
            }

            $contracts = RentalContract::where('customer_id', $locked->id)->get();
            if ($contracts->contains(fn (RentalContract $contract): bool => $this->deposits->totals($contract)['balance'] > 0)) {
                $this->blocked('Le client possède une caution encore détenue.');
            }

            $contractIds = $contracts->pluck('id');
            $damageIds = DB::table('damage_reports')->where('tenant_id', $locked->tenant_id)->whereIn('rental_contract_id', $contractIds)->pluck('id');
            if (InsuranceClaim::whereNotIn('status', ['rejected', 'closed'])
                ->where(fn ($query) => $query->whereIn('rental_contract_id', $contractIds)->orWhereIn('damage_report_id', $damageIds))
                ->exists()) {
                $this->blocked('Le client possède un sinistre ouvert.');
            }

            $locked->delete();
            $this->audit->record('customer.archived', $locked, ['archived' => false], ['archived' => true]);
        });
    }

    private function blocked(string $message): never
    {
        throw ValidationException::withMessages(['customer' => $message]);
    }
}
