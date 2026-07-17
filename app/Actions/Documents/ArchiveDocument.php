<?php

namespace App\Actions\Documents;

use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Driver;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArchiveDocument
{
    public function __construct(
        private readonly RequireValidCurrentDocument $documents,
        private readonly AgencyAccess $agencyAccess,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Document $document): void
    {
        DB::transaction(function () use ($document): void {
            $locked = Document::with('documentable')->whereKey($document)->lockForUpdate()->firstOrFail();
            $this->agencyAccess->required($locked->agency_id);

            if (DB::table('contract_versions')->where('tenant_id', $locked->tenant_id)->where('document_id', $locked->id)->exists()) {
                $this->blocked('Ce document est référencé par une version contractuelle.');
            }
            if ($this->isRequiredByNonTerminalContract($locked)) {
                $this->blocked('Ce document est requis par un contrat non terminal.');
            }
            if ($this->isLastRequiredVerificationDocument($locked)) {
                $this->blocked('Ce document est la dernière pièce valide requise pour une personne vérifiée.');
            }

            $locked->delete();
            $this->audit->record('document.archived', $locked, ['archived' => false], [
                'archived' => true,
                'document_type' => $locked->document_type->value,
                'documentable_type' => $locked->documentable_type,
                'documentable_id' => $locked->documentable_id,
            ]);
        });
    }

    private function isRequiredByNonTerminalContract(Document $document): bool
    {
        $terminal = ['closed', 'cancelled'];

        return match ($document->documentable_type) {
            (new Customer)->getMorphClass() => $document->document_type === DocumentType::CustomerIdentity
                && RentalContract::where('customer_id', $document->documentable_id)->whereNotIn('status', $terminal)->exists(),
            (new Driver)->getMorphClass() => $document->document_type === DocumentType::DrivingLicence && DB::table('contract_drivers as cd')
                ->join('rental_contracts as rc', function ($join): void {
                    $join->on('rc.tenant_id', '=', 'cd.tenant_id')->on('rc.id', '=', 'cd.rental_contract_id');
                })
                ->where('cd.tenant_id', $document->tenant_id)
                ->where('cd.driver_id', $document->documentable_id)
                ->whereNotIn('rc.status', $terminal)
                ->exists(),
            (new RentalContract)->getMorphClass() => RentalContract::whereKey($document->documentable_id)->whereNotIn('status', $terminal)->exists(),
            default => false,
        };
    }

    private function isLastRequiredVerificationDocument(Document $document): bool
    {
        $owner = $document->documentable;
        if ($owner instanceof Customer
            && $owner->verification_status === VerificationStatus::Verified
            && $document->document_type === DocumentType::CustomerIdentity) {
            return ! $this->documents->hasAnotherValid($owner, DocumentType::CustomerIdentity, $document->id);
        }
        if ($owner instanceof Driver
            && $owner->verification_status === VerificationStatus::Verified
            && $document->document_type === DocumentType::DrivingLicence) {
            return ! $this->documents->hasAnotherValid($owner, DocumentType::DrivingLicence, $document->id);
        }

        return false;
    }

    private function blocked(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
