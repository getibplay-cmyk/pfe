<?php

namespace App\Actions\Rentals;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\RentalContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EnsureRequiredContractDocuments
{
    public function handle(RentalContract $contract, Model $customer, Model $driver): void
    {
        $requiredTypes = config('rentals.required_document_types');

        $this->validateDocument($contract, $customer, $requiredTypes[0]);
        $this->validateDocument($contract, $driver, $requiredTypes[1]);

        $contractDocument = $contract->currentVersion?->document()->first();
        if (! $contractDocument
            || $contractDocument->documentable_type !== $contract->getMorphClass()
            || (int) $contractDocument->documentable_id !== (int) $contract->id
            || $contractDocument->document_type !== DocumentType::ContractAcceptance) {
            throw ValidationException::withMessages(['documents' => 'La version contractuelle doit posséder son propre document privé.']);
        }
        $this->validateStoredVersion($contract, $contractDocument);
    }

    private function validateDocument(RentalContract $contract, Model $owner, string $type): void
    {
        $document = Document::query()
            ->where('agency_id', $contract->agency_id)
            ->where('documentable_type', $owner->getMorphClass())
            ->where('documentable_id', $owner->getKey())
            ->where('document_type', $type)
            ->latest('id')
            ->first();

        if (! $document) {
            throw ValidationException::withMessages(['documents' => 'Les documents requis doivent appartenir au tenant et à l’agence du contrat.']);
        }

        $this->validateStoredVersion($contract, $document);
    }

    private function validateStoredVersion(RentalContract $contract, Document $document): void
    {
        if ((int) $document->tenant_id !== (int) $contract->tenant_id || (int) $document->agency_id !== (int) $contract->agency_id) {
            throw ValidationException::withMessages(['documents' => 'Un document requis ne correspond pas au tenant et à l’agence du contrat.']);
        }
        if ($document->trashed() || $document->retention_until?->isPast()) {
            throw ValidationException::withMessages(['documents' => 'Un document requis est obsolète.']);
        }

        $version = $document->currentVersion()->first();
        $latestVersionNumber = $document->versions()->max('version_number');
        if (! $version || $version->document_id !== $document->id || (int) $version->version_number !== (int) $latestVersionNumber) {
            throw ValidationException::withMessages(['documents' => 'Chaque document requis doit posséder une version courante non remplacée.']);
        }

        $disk = Storage::disk(config('documents.disk'));
        if (! $disk->exists($version->stored_path)) {
            throw ValidationException::withMessages(['documents' => 'Le fichier privé d’un document requis est introuvable.']);
        }

        $actualHash = hash('sha256', $disk->get($version->stored_path));
        if (! hash_equals($version->sha256, $actualHash)) {
            throw ValidationException::withMessages(['documents' => 'L’empreinte d’un document requis est incohérente.']);
        }
    }
}
