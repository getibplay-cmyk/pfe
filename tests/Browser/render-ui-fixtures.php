<?php

use App\Models\Agency;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Notifications\NotificationInbox;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;

// Render the real Blade views with synthetic, in-memory presentation data only.
// This is a visual fixture, not an integration test or a database substitute.
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array', 'app.url' => 'http://127.0.0.1:8765']);
$app['session']->driver()->start();
DB::connection()->beforeExecuting(function () {
    throw new LogicException('Visual fixtures must never query the database.');
});
$out = $argv[1] ?? $root.'/storage/framework/testing/ui-fixtures';
if (! is_dir($out)) {
    mkdir($out, 0700, true);
}
$user = new class extends User
{
    public function hasPermission(string $permission): bool
    {
        return true;
    }
};
$user->forceFill(['id' => 1, 'name' => 'Administrateur de l’entreprise', 'is_platform_admin' => false, 'tenant_id' => 1]);
$user->setRelation('tenant', new Tenant(['name' => 'Atlas Location Démo']));
$user->setRelation('agency', null);
$user->setRelation('role', new Role(['slug' => 'tenant-owner', 'is_active' => true]));
$notifications = Mockery::mock(NotificationInbox::class);
$notifications->shouldReceive('recent')->andReturn(collect());
$notifications->shouldReceive('unreadCount')->andReturn(70);
$app->instance(NotificationInbox::class, $notifications);
Auth::setUser($user);
$app['view']->share('errors', new ViewErrorBag);
function renderFixture($name, $routeName, $view, $data = [], $locale = 'fr')
{
    global $app, $user, $out;
    $app->setLocale($locale);
    $route = $app['router']->getRoutes()->getByName($routeName);
    $request = Request::create('http://127.0.0.1:8765/'.($route?->uri() ?? 'dashboard'));
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession($app['session']->driver());
    $app->instance('request', $request);
    $app['url']->setRequest($request);
    $html = str_starts_with($view, '<') ? Blade::render($view, $data) : view($view, $data)->render();
    file_put_contents($out.'/'.$name.'.html', $html);
    echo $name."\n";
}
$dashboard = array_fill_keys(['dashboardStatistics', 'maintenanceSummary', 'insuranceSummary', 'upcomingReservations', 'expectedReturns', 'unavailableVehicles', 'unpaidInvoices', 'upcomingMaintenance', 'openClaims', 'expiringPolicies', 'uninsuredVehicles', 'expiringDocuments', 'expiringLicences', 'recentActivity', 'billingState'], null);
$dashboard['kpis'] = ['Véhicules actifs' => 24, 'Réservations confirmées' => 12, 'Contrats en cours' => 8, 'Encaissements' => '28 450,00 MAD'];
$dashboard['actionGroups'] = [];
foreach (['Retours en retard', 'Retours à venir aujourd’hui', 'Contrats à préparer aujourd’hui'] as $i => $title) {
    $dashboard['actionGroups'][] = ['key' => 'group-'.$i, 'title' => $title, 'count' => 0, 'items' => [], 'url' => route('contracts.index')];
}
renderFixture('dashboard', 'dashboard', 'dashboard', $dashboard);
renderFixture('dashboard-ar', 'dashboard', 'dashboard', $dashboard, 'ar');
renderFixture('login', 'login', 'auth.login');
$vehicle = new Vehicle(['agency_id' => 1, 'vehicle_category_id' => 1, 'operational_status' => 'active']);
$form = ['vehicle' => $vehicle, 'agencies' => collect([new Agency(['id' => 1, 'name' => 'Agence Casablanca'])]), 'categories' => collect([new VehicleCategory(['id' => 1, 'name' => 'Citadine'])]), 'colorAssistantEnabled' => true, 'colorAssistantReady' => false, 'registrationAssistantEnabled' => true, 'registrationAssistantFullReady' => false, 'registrationAssistantCloseUpReady' => false];
renderFixture('vehicle', 'vehicles.create', 'vehicles.form', $form);
$components = <<<'BLADE'
<x-app-layout><div class="rf-page">
<x-page-header title="Contrôle de l’interface" eyebrow="Aperçu de validation" description="Composants réels du projet, données fictives pour vérifier les interactions." />
<x-flash-message type="success" message="Les modifications ont été enregistrées." />
<x-flash-message type="warning" message="Une échéance demande votre attention." />
<x-flash-message type="error" message="Veuillez corriger les champs indiqués." />
<x-section-card title="Confirmation d’une action">
<form method="POST" action="/fixture-submit" x-sanad-pilot-confirm data-confirm-title="Archiver le document" data-confirm-resource="Document de démonstration" data-confirm-consequence="Le document sera retiré de la liste active." data-confirm-label="Archiver">
@csrf <input type="hidden" name="record" value="fixture"><button type="submit" name="action" value="archive" class="rf-button-danger">Archiver le document</button></form>
</x-section-card>
<x-section-card title="Formulaires et états"><div class="grid gap-4 sm:grid-cols-2">
<x-password-field id="demo-password" name="password" label="Mot de passe de démonstration" />
<x-file-input id="demo-file" name="file" label="Photo du véhicule" accept="image/jpeg,image/png" preview="image" />
</div></x-section-card>
<x-responsive-table label="Exemple de liste"><table><thead><tr><th>Réservation</th><th>Véhicule</th><th>État</th><th>Montant</th><th>Actions</th></tr></thead><tbody>
<tr><td>RES-2026-000001</td><td>Renault Clio • Agence Casablanca</td><td><x-status-badge value="confirmed" /></td><td>1 250,00 MAD</td><td><a class="rf-button-link" href="/vehicle">Consulter</a></td></tr>
</tbody></table></x-responsive-table>
<x-section-card title="Photos"><x-photo-gallery :images="[['src' => '/sample.svg', 'alt' => 'Véhicule de démonstration'] ]" /></x-section-card>
</div></x-app-layout>
BLADE;
renderFixture('components', 'dashboard', $components);
renderFixture('components-ar', 'dashboard', $components, [], 'ar');
renderFixture('error', 'dashboard', '<x-error-page code="404" title="Page introuvable" message="La page demandée n’existe pas ou n’est plus disponible." />');
