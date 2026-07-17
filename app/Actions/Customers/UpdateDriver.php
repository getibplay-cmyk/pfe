<?php

namespace App\Actions\Customers;

use App\Models\Driver;
use App\Support\Audit\AuditRecorder;
use App\Support\SensitiveData\IdentityProtector;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDriver
{
    public function __construct(
        private readonly IdentityProtector $protector,
        private readonly AgencyAccess $agencyAccess,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Driver $driver, array $data): Driver
    {
        unset($data['tenant_id'], $data['customer_id'], $data['agency_id'], $data['verification_status']);
        if (! empty($data['licence_issued_at']) && $data['licence_issued_at'] > $data['licence_expires_at']) {
            throw ValidationException::withMessages(['licence_issued_at' => 'La date de délivrance doit précéder ou égaler la date d’expiration.']);
        }

        $licenceNumber = $data['licence_number'] ?? null;
        unset($data['licence_number']);

        try {
            return DB::transaction(function () use ($driver, $data, $licenceNumber): Driver {
                $locked = Driver::with('customer')->whereKey($driver)->lockForUpdate()->firstOrFail();
                $this->agencyAccess->required($locked->customer->agency_id);
                $old = $locked->only(['first_name', 'last_name', 'birth_date', 'licence_category', 'licence_issued_at', 'licence_expires_at', 'is_primary']);

                if ((bool) ($data['is_primary'] ?? false)) {
                    Driver::where('customer_id', $locked->customer_id)->whereKeyNot($locked->id)->where('is_primary', true)->lockForUpdate()->update(['is_primary' => false]);
                }

                $locked->fill($data);
                if ($licenceNumber !== null && $licenceNumber !== '') {
                    $protected = $this->protector->protect($licenceNumber);
                    $locked->forceFill(['licence_number_encrypted' => $protected['encrypted'], 'licence_number_hash' => $protected['hash']]);
                }
                $locked->save();
                $new = $locked->refresh();
                $this->audit->record('driver.updated', $new, $old, [
                    ...$new->only(array_keys($old)),
                    'licence_number_changed' => $licenceNumber !== null && $licenceNumber !== '',
                ]);

                return $new;
            });
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23505', '23514'], true)) {
                throw ValidationException::withMessages(['is_primary' => 'Le conducteur principal ou les dates du permis sont incompatibles.']);
            }

            throw $exception;
        }
    }
}
