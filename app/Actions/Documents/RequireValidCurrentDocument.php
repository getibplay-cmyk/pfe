<?php

namespace App\Actions\Documents;

use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RequireValidCurrentDocument
{
    public function handle(Model $owner, DocumentType $type, string $field = 'document'): Document
    {
        $document = $owner->documents()
            ->where('document_type', $type->value)
            ->with('currentVersion')
            ->latest('id')
            ->get()
            ->first(fn (Document $candidate): bool => $this->isValid($candidate));

        if (! $document) {
            throw ValidationException::withMessages([
                $field => 'Un document privé courant, physiquement présent et intègre est obligatoire.',
            ]);
        }

        return $document;
    }

    public function hasAnotherValid(Model $owner, DocumentType $type, int $excludedDocumentId): bool
    {
        return $owner->documents()
            ->whereKeyNot($excludedDocumentId)
            ->where('document_type', $type->value)
            ->with('currentVersion')
            ->get()
            ->contains(fn (Document $candidate): bool => $this->isValid($candidate));
    }

    public function isValid(Document $document): bool
    {
        if ($document->retention_until?->isPast()) {
            return false;
        }

        $version = $document->currentVersion;
        if (! $version) {
            return false;
        }

        $disk = Storage::disk(config('documents.disk'));
        try {
            if (! $disk->exists($version->stored_path) || $disk->size($version->stored_path) !== (int) $version->size_bytes) {
                return false;
            }

            $stream = $disk->readStream($version->stored_path);
            if (! is_resource($stream)) {
                return false;
            }

            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            fclose($stream);

            return hash_equals((string) $version->sha256, hash_final($hash));
        } catch (\Throwable) {
            return false;
        }
    }
}
