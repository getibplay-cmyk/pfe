<?php

namespace App\Actions\Customers;

use App\Actions\Documents\RequireValidCurrentDocument;
use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Models\Driver;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VerifyDriver
{
    public function __construct(
        private readonly RequireValidCurrentDocument $documents,
        private readonly AgencyAccess $agencyAccess,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Driver $driver): Driver
    {
        return DB::transaction(function () use ($driver): Driver {
            $locked = Driver::with('customer')->whereKey($driver)->lockForUpdate()->firstOrFail();
            $this->agencyAccess->required($locked->customer->agency_id);
            if ($locked->verification_status === VerificationStatus::Verified) {
                return $locked;
            }
            if ($locked->licence_expires_at->isBefore(today())) {
                throw ValidationException::withMessages(['licence_expires_at' => 'Le permis doit être en cours de validité.']);
            }
            if ($locked->licence_issued_at?->isAfter($locked->licence_expires_at)) {
                throw ValidationException::withMessages(['licence_issued_at' => 'La date de délivrance doit précéder l’expiration.']);
            }

            $document = $this->documents->handle($locked, DocumentType::DrivingLicence, 'verification');
            $from = $locked->verification_status->value;
            $locked->forceFill(['verification_status' => VerificationStatus::Verified])->save();
            $this->audit->record('driver.verification.verified', $locked, ['verification_status' => $from], [
                'verification_status' => VerificationStatus::Verified->value,
                'document_id' => $document->id,
            ]);

            return $locked->refresh();
        });
    }
}
