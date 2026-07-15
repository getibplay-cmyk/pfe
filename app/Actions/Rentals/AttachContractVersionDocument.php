<?php

namespace App\Actions\Rentals;

use App\Actions\Documents\StorePrivateDocument;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\RentalContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachContractVersionDocument
{
    public function __construct(private readonly StorePrivateDocument $documents) {}

    public function handle(RentalContract $contract, UploadedFile $file, int $actorId): Document
    {
        return DB::transaction(function () use ($contract, $file, $actorId) {
            $locked = RentalContract::with('currentVersion')->whereKey($contract)->lockForUpdate()->firstOrFail();
            $version = $locked->currentVersion;
            if (! $version || $version->locked_at) {
                throw ValidationException::withMessages(['document' => 'Une version contractuelle courante non verrouillée est requise.']);
            }
            if ($version->document_id) {
                throw ValidationException::withMessages(['document' => 'Le document de cette version ne peut pas être remplacé ; créez une nouvelle version contractuelle.']);
            }

            $document = $this->documents->handle($locked, [
                'document_type' => DocumentType::ContractAcceptance,
                'title' => 'Contrat '.$locked->contract_number.' — version '.$version->version_number,
                'is_sensitive' => true,
            ], $file, $actorId);
            $version->forceFill(['document_id' => $document->id])->save();

            return $document;
        });
    }
}
