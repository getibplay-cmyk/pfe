<?php

namespace Tests\Feature;

use App\Actions\Documents\AddDocumentVersion;
use App\Actions\Documents\DownloadPrivateDocument;
use App\Models\Agency;
use App\Models\Document;
use App\Support\Security\MalwareScanner;
use App\Support\Security\PreparedDocumentUpload;
use App\Support\Security\UploadInspection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SecurityHardeningFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_failure_prevents_document_publication_and_leaves_no_private_object(): void
    {
        Storage::fake('local');
        config(['security.uploads.scan_required' => true]);
        $this->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function assertClean(string $path): void
            {
                throw new \RuntimeException('synthetic scanner failure');
            }
        });
        $owner = $this->createTenantOwner();
        app(TenantContext::class)->run($owner->tenant_id, function () use ($owner) {
            $document = $this->document($owner->id);
            try {
                app(AddDocumentVersion::class)->handle($document, $this->pdf(), $owner->id);
                $this->fail('An unavailable scanner must not publish the document.');
            } catch (ValidationException) {
                $this->assertSame(0, $document->versions()->count());
                $this->assertSame([], Storage::disk('local')->allFiles());
            }
        });
    }

    public function test_changed_bytes_are_not_downloaded_even_when_the_path_and_authorization_are_valid(): void
    {
        Storage::fake('local');
        config(['security.uploads.scan_required' => false]);
        $owner = $this->createTenantOwner();
        app(TenantContext::class)->run($owner->tenant_id, function () use ($owner) {
            $document = $this->document($owner->id);
            $version = app(AddDocumentVersion::class)->handle($document, $this->pdf(), $owner->id);
            Storage::disk('local')->put($version->stored_path, str_repeat('X', $version->size_bytes));
            try {
                app(DownloadPrivateDocument::class)->handle($document->fresh(), $owner->id);
                $this->fail('Tampered bytes must never be delivered.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        });
    }

    public function test_scan_rejects_changes_during_inspection(): void
    {
        config(['security.uploads.scan_required' => true]);
        $file = $this->pdf();
        $inspector = new UploadInspection(new class implements MalwareScanner
        {
            public function assertClean(string $path): void
            {
                file_put_contents($path, 'changed');
            }
        });
        $this->expectException(ValidationException::class);
        $inspector->inspect($file->getRealPath());
    }

    public function test_image_reencoding_removes_appended_metadata_and_keeps_a_decodable_image(): void
    {
        $original = UploadedFile::fake()->image('photo.jpg', 32, 24);
        file_put_contents($original->getRealPath(), 'PRIVATE-GPS-SYNTHETIC', FILE_APPEND);
        $prepared = new PreparedDocumentUpload($original);
        $this->assertStringNotContainsString('PRIVATE-GPS-SYNTHETIC', $prepared->file->getContent());
        $this->assertSame([32, 24], array_slice(getimagesize($prepared->file->getRealPath()), 0, 2));
    }

    private function document(int $ownerId): Document
    {
        $agency = Agency::factory()->create();

        return Document::create(['agency_id' => $agency->id, 'documentable_type' => 'customer', 'documentable_id' => 1, 'document_type' => 'other', 'title' => 'Synthetic security fixture', 'created_by' => $ownerId]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\nSynthetic fixture\n%%EOF");
    }
}
