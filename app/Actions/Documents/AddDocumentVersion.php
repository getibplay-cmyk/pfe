<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentVersion;
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
        $disk = Storage::disk(config('documents.disk'));
        $extension = strtolower($file->guessExtension() ?: $file->extension());
        $path = 'tenants/'.$document->tenant_id.'/documents/'.$document->id.'/'.Str::uuid().'.'.$extension;
        $stored = $disk->putFileAs(dirname($path), $file, basename($path));
        if (! $stored) {
            throw ValidationException::withMessages(['file' => 'Le document n’a pas pu être stocké.']);
        }

        try {
            return DB::transaction(function () use ($document, $file, $actorId, $stored, $disk) {
                $locked = Document::whereKey($document)->lockForUpdate()->firstOrFail();
                $version = DocumentVersion::create([
                    'document_id' => $locked->id,
                    'version_number' => ((int) $locked->versions()->max('version_number')) + 1,
                    'original_name' => basename($file->getClientOriginalName()),
                    'stored_path' => $stored,
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'sha256' => hash('sha256', $disk->get($stored)),
                    'uploaded_by' => $actorId,
                ]);
                $locked->forceFill(['current_version_id' => $version->id])->save();
                DocumentAccessLog::create(['document_id' => $locked->id, 'document_version_id' => $version->id, 'user_id' => $actorId, 'action' => 'upload_version', 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

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
        if ($dangerous || ! in_array($extension, config('documents.allowed_extensions'), true)
            || ! in_array($file->getMimeType(), config('documents.allowed_mime_types'), true)
            || $file->getSize() > config('documents.max_size_kb') * 1024) {
            throw ValidationException::withMessages(['file' => 'Type, extension ou taille de document non autorisé.']);
        }
    }
}
