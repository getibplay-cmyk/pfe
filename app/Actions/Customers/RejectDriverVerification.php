<?php

namespace App\Actions\Customers;

use App\Enums\VerificationStatus;
use App\Models\Driver;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectDriverVerification
{
    public function __construct(private readonly AgencyAccess $agencyAccess, private readonly AuditRecorder $audit) {}

    public function handle(Driver $driver, string $reason): Driver
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif du rejet est obligatoire.']);
        }

        return DB::transaction(function () use ($driver, $reason): Driver {
            $locked = Driver::with('customer')->whereKey($driver)->lockForUpdate()->firstOrFail();
            $this->agencyAccess->required($locked->customer->agency_id);
            if ($locked->verification_status === VerificationStatus::Rejected) {
                return $locked;
            }

            $from = $locked->verification_status->value;
            $locked->forceFill(['verification_status' => VerificationStatus::Rejected])->save();
            $this->audit->record('driver.verification.rejected', $locked, ['verification_status' => $from], [
                'verification_status' => VerificationStatus::Rejected->value,
                'reason_digest' => hash('sha256', $reason),
                'reason_length' => mb_strlen($reason),
            ]);

            return $locked->refresh();
        });
    }
}
