<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Support\Security\UploadInspection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadPrivateDocument
{
    public function handle(Document $document, ?int $actorId): StreamedResponse
    {
        $version = $document->currentVersion()->firstOrFail();
        $prefix = 'tenants/'.$document->tenant_id.'/documents/'.$document->id.'/';
        abort_unless(str_starts_with($version->stored_path, $prefix)
            && preg_match('/^[a-f0-9-]{36}\.(?:pdf|jpe?g|png|webp)$/iD', substr($version->stored_path, strlen($prefix))) === 1
            && in_array($version->mime_type, config('documents.allowed_mime_types'), true)
            && (int) $version->size_bytes > 0, 404);
        $disk = Storage::disk(config('documents.disk'));
        $root = realpath($disk->path(''));
        $candidate = $disk->path($version->stored_path);
        $resolved = realpath($candidate);
        abort_unless(is_string($root) && is_string($resolved) && ! is_link($candidate)
            && str_starts_with(str_replace('\\', '/', $resolved), rtrim(str_replace('\\', '/', $root), '/').'/')
            && (int) $version->size_bytes <= (int) config('security.uploads.max_bytes', 10_485_760), 404);
        $input = $disk->readStream($version->stored_path);
        $copy = tmpfile();
        if (! is_resource($input) || ! is_resource($copy)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($copy)) {
                fclose($copy);
            }
            abort(404);
        }
        try {
            $limit = (int) config('security.uploads.max_bytes', 10_485_760);
            $copied = stream_copy_to_stream($input, $copy, $limit + 1);
            $path = stream_get_meta_data($copy)['uri'];
            abort_unless($copied !== false && $copied <= $limit && $copied === (int) $version->size_bytes
                && hash_equals($version->sha256, (string) hash_file('sha256', $path)), 409);
            app(UploadInspection::class)->inspect($path);
            rewind($copy);
            DocumentAccessLog::create(['document_id' => $document->id, 'document_version_id' => $version->id, 'user_id' => $actorId, 'action' => 'download', 'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000)]);
        } catch (\Throwable $exception) {
            fclose($copy);
            throw $exception;
        } finally {
            fclose($input);
        }

        // Deliver the exact inspected bytes, even if the stored object is changed afterwards.
        return response()->streamDownload(function () use ($copy) {
            try {
                fpassthru($copy);
            } finally {
                fclose($copy);
            }
        }, $version->original_name, ['Content-Type' => $version->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox', 'Cache-Control' => 'no-store, private']);
    }
}
