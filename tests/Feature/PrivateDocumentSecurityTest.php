<?php

namespace Tests\Feature;

use App\Support\Testing\TestDatabaseGuard;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PrivateDocumentSecurityTest extends TestCase
{
    public function test_private_disk_is_not_served_and_has_no_public_route(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $database = $this->assertUsesAuthorizedPostgreSqlTestDatabase();
        $privateRoot = config('filesystems.disks.local.root');

        if ($database === TestDatabaseGuard::ACCEPTANCE_DATABASE) {
            $acceptanceRoot = realpath('C:\tmp\RentFleet06G');
            $resolvedPrivateRoot = realpath($privateRoot);

            $this->assertNotFalse($acceptanceRoot);
            $this->assertNotFalse($resolvedPrivateRoot);
            $this->assertStringStartsWith(
                rtrim($acceptanceRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
                $resolvedPrivateRoot
            );
        } else {
            $this->assertSame(storage_path('app/private'), $privateRoot);
        }

        $this->assertNotSame(config('filesystems.disks.public.root'), config('filesystems.disks.local.root'));
        $this->get('/storage/private-document.pdf')->assertNotFound();

        $publicStorageRoute = collect(Route::getRoutes())->contains(
            fn ($route) => str_starts_with($route->uri(), 'storage/{')
        );
        $this->assertFalse($publicStorageRoute);
    }

    public function test_document_download_route_keeps_auth_tenant_and_policy_layers(): void
    {
        $route = Route::getRoutes()->getByName('documents.download');

        $this->assertNotNull($route);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant', $route->gatherMiddleware());
        $controller = file_get_contents(app_path('Http/Controllers/DocumentController.php'));
        $this->assertStringContainsString("authorize('download', \$document)", $controller);
        $this->assertStringNotContainsString('Storage::url', file_get_contents(app_path('Actions/Documents/DownloadPrivateDocument.php')));
    }
}
