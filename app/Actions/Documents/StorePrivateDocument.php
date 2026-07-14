<?php

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StorePrivateDocument
{
    public function __construct(private readonly AddDocumentVersion $versions) {}

    public function handle(Model $documentable, array $data, UploadedFile $file, ?int $actorId): Document
    {
        return DB::transaction(function () use ($documentable, $data, $file, $actorId) {
            $document = Document::create([
                'agency_id' => $documentable->getAttribute('agency_id'),
                'documentable_type' => $documentable->getMorphClass(),
                'documentable_id' => $documentable->getKey(),
                'document_type' => $data['document_type'],
                'title' => $data['title'],
                'retention_until' => $data['retention_until'] ?? null,
                'is_sensitive' => $data['is_sensitive'] ?? false,
                'created_by' => $actorId,
            ]);
            $this->versions->handle($document, $file, $actorId);

            return $document->refresh();
        });
    }
}
