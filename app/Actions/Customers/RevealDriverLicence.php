<?php

namespace App\Actions\Customers;

use App\Models\Driver;
use App\Support\Audit\AuditRecorder;
use App\Support\SensitiveData\IdentityProtector;
use App\Support\Tenancy\AgencyAccess;

class RevealDriverLicence
{
    public function __construct(
        private readonly IdentityProtector $protector,
        private readonly AgencyAccess $agencyAccess,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Driver $driver): string
    {
        $driver->loadMissing('customer');
        $this->agencyAccess->required($driver->customer->agency_id);
        $this->audit->record('driver.licence.viewed', $driver);

        return $this->protector->reveal($driver->licence_number_encrypted);
    }
}
