<?php

namespace App\Actions\Customers;

use App\Enums\VerificationStatus;
use App\Models\Customer;
use App\Models\Driver;
use App\Support\SensitiveData\IdentityProtector;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateDriver
{
    public function __construct(
        private readonly IdentityProtector $protector,
        private readonly AgencyAccess $agencyAccess,
    ) {}

    public function handle(Customer $customer, array $data): Driver
    {
        $this->agencyAccess->required($customer->agency_id);
        if (! empty($data['licence_issued_at']) && $data['licence_issued_at'] > $data['licence_expires_at']) {
            throw ValidationException::withMessages(['licence_issued_at' => 'La date de délivrance doit précéder ou égaler la date d’expiration.']);
        }

        $protected = $this->protector->protect($data['licence_number']);
        unset($data['licence_number']);
        $data['verification_status'] = ($data['verification_status'] ?? null) instanceof VerificationStatus
            ? $data['verification_status']
            : VerificationStatus::Pending;

        try {
            return DB::transaction(function () use ($customer, $data, $protected): Driver {
                Customer::whereKey($customer)->lockForUpdate()->firstOrFail();
                if ((bool) ($data['is_primary'] ?? false)) {
                    Driver::where('customer_id', $customer->id)->where('is_primary', true)->lockForUpdate()->update(['is_primary' => false]);
                }

                return Driver::forceCreate([...$data, 'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id, 'licence_number_encrypted' => $protected['encrypted'], 'licence_number_hash' => $protected['hash']]);
            });
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23505', '23514'], true)) {
                throw ValidationException::withMessages(['is_primary' => 'Le conducteur principal ou les dates du permis sont incompatibles.']);
            }

            throw $exception;
        }
    }
}
