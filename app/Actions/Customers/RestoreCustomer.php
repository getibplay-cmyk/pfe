<?php

namespace App\Actions\Customers;

use App\Models\Agency;
use App\Models\Customer;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestoreCustomer
{
    public function __construct(private readonly AgencyAccess $agencyAccess, private readonly AuditRecorder $audit) {}

    public function handle(Customer $customer): Customer
    {
        return DB::transaction(function () use ($customer): Customer {
            $locked = Customer::withTrashed()->whereKey($customer)->lockForUpdate()->firstOrFail();
            if (! $locked->trashed()) {
                return $locked;
            }
            $this->agencyAccess->required($locked->agency_id);
            if (! Agency::whereKey($locked->agency_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['customer' => 'L’agence du client doit être active avant restauration.']);
            }

            $locked->restore();
            $this->audit->record('customer.restored', $locked, ['archived' => true], ['archived' => false]);

            return $locked->refresh();
        });
    }
}
