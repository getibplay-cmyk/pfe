<?php

namespace App\Actions\Customers;

use App\Actions\Documents\RequireValidCurrentDocument;
use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Models\Customer;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;

class VerifyCustomer
{
    public function __construct(
        private readonly RequireValidCurrentDocument $documents,
        private readonly AgencyAccess $agencyAccess,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Customer $customer, ?int $actorId = null): Customer
    {
        return DB::transaction(function () use ($customer): Customer {
            $locked = Customer::whereKey($customer)->lockForUpdate()->firstOrFail();
            $this->agencyAccess->required($locked->agency_id);

            if ($locked->verification_status === VerificationStatus::Verified) {
                return $locked;
            }

            $document = $this->documents->handle($locked, DocumentType::CustomerIdentity, 'verification');
            $from = $locked->verification_status->value;
            $locked->forceFill(['verification_status' => VerificationStatus::Verified])->save();
            $this->audit->record('customer.verification.verified', $locked, ['verification_status' => $from], [
                'verification_status' => VerificationStatus::Verified->value,
                'document_id' => $document->id,
            ]);

            return $locked->refresh();
        });
    }
}
