<?php

use App\Actions\Intelligence\ImportJ11SyntheticAdvisory;
use App\Enums\J11AdvisoryModule;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $actor = User::query()->findOrFail((int) ($argv[1] ?? 0));
    $module = J11AdvisoryModule::from((string) ($argv[2] ?? ''));

    config(['intelligence.contract_demo.enabled' => true]);
    app(TenantContext::class)->setFromUser($actor);

    $request = Request::create('/tests/j12-concurrent-import', 'POST');
    $request->setUserResolver(static fn (): User => $actor);
    $request->attributes->set('correlation_id', (string) Str::uuid());
    $app->instance('request', $request);

    DB::statement("SET application_name = 'rentfleet_j12_concurrent_import'");

    $result = app(ImportJ11SyntheticAdvisory::class)->handle($module, $actor);

    fwrite(STDOUT, json_encode([
        'created' => $result->created,
        'database' => DB::connection()->getDatabaseName(),
        'record_id' => $result->record->id,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.' ['.$exception->getCode().']');

    exit(1);
}
