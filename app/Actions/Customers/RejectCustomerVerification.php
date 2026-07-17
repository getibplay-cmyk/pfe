<?php

namespace App\Actions\Customers;

use App\Enums\VerificationStatus;
use App\Models\Customer;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectCustomerVerification
{
    public function __construct(private readonly AgencyAccess $agencyAccess, private readonly AuditRecorder $audit) {}

    public function handle(Customer $customer, string $reason): Customer
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif du rejet est obligatoire.']);
        }

        return DB::transaction(function () use ($customer, $reason): Customer {
            $locked = Customer::whereKey($customer)->lockForUpdate()->firstOrFail();
            $this->agencyAccess->required($locked->agency_id);
            if ($locked->verification_status === VerificationStatus::Rejected) {
                return $locked;
            }

            $from = $locked->verification_status->value;
            $locked->forceFill(['verification_status' => VerificationStatus::Rejected])->save();
            $this->audit->record('customer.verification.rejected', $locked, ['verification_status' => $from], [
                'verification_status' => VerificationStatus::Rejected->value,
                'reason_digest' => hash('sha256', $reason),
                'reason_length' => mb_strlen($reason),
            ]);

            return $locked->refresh();
        });
    }
}
