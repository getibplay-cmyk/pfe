<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Driver;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestoreDriver
{
    public function __construct(private readonly AgencyAccess $agencyAccess, private readonly AuditRecorder $audit) {}

    public function handle(Driver $driver): Driver
    {
        return DB::transaction(function () use ($driver): Driver {
            $locked = Driver::withTrashed()->whereKey($driver)->lockForUpdate()->firstOrFail();
            if (! $locked->trashed()) {
                return $locked;
            }
            $customer = Customer::whereKey($locked->customer_id)->first();
            if (! $customer) {
                throw ValidationException::withMessages(['driver' => 'Le client doit être actif avant de restaurer ce conducteur.']);
            }
            $this->agencyAccess->required($customer->agency_id);

            $locked->forceFill(['is_primary' => false]);
            $locked->restore();
            $this->audit->record('driver.restored', $locked, ['archived' => true], ['archived' => false, 'is_primary' => false]);

            return $locked->refresh();
        });
    }
}
