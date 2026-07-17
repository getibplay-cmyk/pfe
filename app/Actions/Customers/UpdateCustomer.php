<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Driver;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCustomer
{
    public function __construct(
        private readonly AgencyAccess $agencyAccess,
        private readonly UpdateCustomerIdentity $identity,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Customer $customer, array $data): Customer
    {
        unset($data['tenant_id'], $data['verification_status']);
        $data['agency_id'] = $this->agencyAccess->required($data['agency_id'] ?? null);
        $identityNumber = $data['identity_number'] ?? null;
        unset($data['identity_number']);

        try {
            return DB::transaction(function () use ($customer, $data, $identityNumber): Customer {
                $locked = Customer::whereKey($customer)->lockForUpdate()->firstOrFail();
                $old = $locked->only(['agency_id', 'customer_type', 'first_name', 'last_name', 'company_name', 'email', 'phone', 'city', 'nationality', 'birth_date', 'identity_type']);

                if ((int) $locked->agency_id !== (int) $data['agency_id']) {
                    $this->ensureAgencyReassignmentIsSafe($locked, (int) $data['agency_id']);
                }

                $locked->update($data);
                if ($identityNumber !== null && $identityNumber !== '') {
                    $this->identity->handle($locked, $identityNumber, $data['identity_type'] ?? null);
                }

                $new = $locked->refresh();
                $this->audit->record('customer.updated', $new, $old, [
                    ...$new->only(array_keys($old)),
                    'identity_number_changed' => $identityNumber !== null && $identityNumber !== '',
                ]);

                return $new;
            });
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23503', '23505', '23514'], true)) {
                throw ValidationException::withMessages(['agency_id' => 'Le changement d’agence est incompatible avec les données historiques du client.']);
            }

            throw $exception;
        }
    }

    private function ensureAgencyReassignmentIsSafe(Customer $customer, int $newAgencyId): void
    {
        foreach (['reservations', 'rental_contracts', 'invoices', 'payments', 'payment_allocations'] as $table) {
            if (DB::table($table)->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)->where('agency_id', '<>', $newAgencyId)->exists()) {
                throw ValidationException::withMessages(['agency_id' => 'Ce client possède des opérations dans une autre agence.']);
            }
        }

        $customerDocumentConflict = DB::table('documents')
            ->where('tenant_id', $customer->tenant_id)
            ->where('documentable_type', $customer->getMorphClass())
            ->where('documentable_id', $customer->id)
            ->where('agency_id', '<>', $newAgencyId)
            ->exists();
        $driverIds = Driver::withTrashed()->where('customer_id', $customer->id)->pluck('id');
        $driverDocumentConflict = DB::table('documents')
            ->where('tenant_id', $customer->tenant_id)
            ->where('documentable_type', (new Driver)->getMorphClass())
            ->whereIn('documentable_id', $driverIds)
            ->where('agency_id', '<>', $newAgencyId)
            ->exists();

        if ($customerDocumentConflict || $driverDocumentConflict) {
            throw ValidationException::withMessages(['agency_id' => 'Les documents privés du client ou de ses conducteurs appartiennent à une autre agence.']);
        }
    }
}
