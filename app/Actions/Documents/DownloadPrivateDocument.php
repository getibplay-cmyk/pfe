<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadPrivateDocument
{
    public function handle(Document $document, ?int $actorId): StreamedResponse
    {
        $version = $document->currentVersion()->firstOrFail();
        abort_unless(Storage::disk(config('documents.disk'))->exists($version->stored_path), 404);
        DocumentAccessLog::create(['document_id' => $document->id, 'document_version_id' => $version->id, 'user_id' => $actorId, 'action' => 'download', 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

        return Storage::disk(config('documents.disk'))->download($version->stored_path, $version->original_name, ['Content-Type' => $version->mime_type]);
    }
}
