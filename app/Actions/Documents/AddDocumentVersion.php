<?php

namespace App\Actions\Documents;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentVersion;
use App\Support\Security\PreparedDocumentUpload;
use App\Support\Security\UploadInspection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AddDocumentVersion
{
    public function handle(Document $document, UploadedFile $file, ?int $actorId): DocumentVersion
    {
        $this->validateFile($file);
        $inspection = app(UploadInspection::class);
        $originalHash = $inspection->inspect($file->getRealPath());
        $prepared = new PreparedDocumentUpload($file);
        $inspectedHash = $prepared->file === $file ? $originalHash : $inspection->inspect($prepared->file->getRealPath());
        $file = $prepared->file;
        $disk = Storage::disk(config('documents.disk'));
        $extension = strtolower($file->guessExtension() ?: $file->extension());
        $path = 'tenants/'.$document->tenant_id.'/documents/'.$document->id.'/'.Str::uuid().'.'.$extension;
        $stored = $disk->putFileAs(dirname($path), $file, basename($path));
        if (! $stored) {
            throw ValidationException::withMessages(['file' => __('Le document n’a pas pu être stocké.')]);
        }
        if (! hash_equals($inspectedHash, hash('sha256', $disk->get($stored)))) {
            $disk->delete($stored);
            throw ValidationException::withMessages(['file' => __('Le fichier a changé pendant sa validation. Recommencez l’envoi.')]);
        }

        try {
            return DB::transaction(function () use ($document, $file, $actorId, $stored, $inspectedHash) {
                $locked = Document::whereKey($document)->lockForUpdate()->firstOrFail();
                if ($locked->document_type === DocumentType::ContractAcceptance && $locked->current_version_id !== null) {
                    throw ValidationException::withMessages(['file' => __('Le document de cette version ne peut pas être remplacé ; créez une nouvelle version contractuelle.')]);
                }
                $version = DocumentVersion::create([
                    'document_id' => $locked->id,
                    'version_number' => ((int) $locked->versions()->max('version_number')) + 1,
                    'original_name' => Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 180, ''),
                    'stored_path' => $stored,
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'sha256' => $inspectedHash,
                    'uploaded_by' => $actorId,
                ]);
                $locked->forceFill(['current_version_id' => $version->id])->save();
                DocumentAccessLog::create(['document_id' => $locked->id, 'document_version_id' => $version->id, 'user_id' => $actorId, 'action' => 'upload_version', 'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000)]);

                return $version;
            });
        } catch (\Throwable $exception) {
            $disk->delete($stored);
            throw $exception;
        }
    }

    private function validateFile(UploadedFile $file): void
    {
        $name = strtolower($file->getClientOriginalName());
        $extension = strtolower($file->getClientOriginalExtension());
        $dangerous = preg_match('/\.(php\d*|phtml|phar|js|html?|exe|bat|cmd|sh)(\.|$)/i', $name);
        $expectedMime = match ($extension) {
            'pdf' => 'application/pdf', 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', default => null,
        };
        if ($dangerous || ! in_array($extension, config('documents.allowed_extensions'), true)
            || $file->getMimeType() !== $expectedMime
            || ! in_array($file->getMimeType(), config('documents.allowed_mime_types'), true)
            || $file->getSize() > config('documents.max_size_kb') * 1024) {
            throw ValidationException::withMessages(['file' => __('Type, extension ou taille de document non autorisé.')]);
        }
    }
}
