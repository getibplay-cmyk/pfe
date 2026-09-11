<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\OnboardingImport;
use App\Models\Tenant;
use App\Models\VehicleCategory;
use App\Support\Audit\AuditRecorder;
use App\Support\Export\SpreadsheetSafeCsv;
use App\Support\Tenancy\OnboardingCsvImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OnboardingImportController extends Controller
{
    private function permit(Request $request): void
    {
        abort_unless($request->user()->isTenantOwner() && $request->user()->hasPermission('vehicle.create') && $request->user()->hasPermission('customer.create'), 403);
    }

    public function index(Request $request)
    {
        $this->permit($request);

        return view('tenant.import', ['agencies' => Agency::where('is_active', true)->when($request->user()->agency_id, fn ($query, $id) => $query->whereKey($id))->orderBy('name')->get(),
            'categories' => VehicleCategory::where('is_active', true)->orderBy('name')->get(['code', 'name']),
        ]);
    }

    public function template(Request $request, string $kind)
    {
        $this->permit($request);
        abort_unless(isset(OnboardingCsvImport::HEADERS[$kind]), 404);

        return response(implode(';', OnboardingCsvImport::HEADERS[$kind])."\r\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="modele-'.$kind.'.csv"']);
    }

    public function store(Request $request, OnboardingCsvImport $service)
    {
        $this->permit($request);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'kind' => ['required', Rule::in(['vehicles', 'customers'])],
            'agency_id' => ['required', 'integer', 'min:1'], 'file' => ['required', 'file', 'max:1024', 'mimes:csv,txt']]);
        $import = $service->preview($request->file('file'), $data['kind'], (int) $data['agency_id'], $request->user());

        return redirect()->route('onboarding.import.show', $import);
    }

    public function show(Request $request, OnboardingImport $import, OnboardingCsvImport $service)
    {
        $this->permit($request);
        abort_unless($import->created_by === $request->user()->id, 403);
        if ($import->completed_at) {
            return redirect()->route('onboarding.index')->with('status', 'Cet import a déjà été confirmé.');
        }
        abort_unless($import->expires_at->gt(now()) && $import->payload !== null, 410);
        $rows = $service->inspect($import);

        return view('tenant.import-preview', ['import' => $import, 'rows' => $rows, 'valid' => ! collect($rows)->contains(fn ($row) => $row['errors'] !== []), 'headers' => OnboardingCsvImport::HEADERS[$import->kind]]);
    }

    public function commit(Request $request, OnboardingImport $import, OnboardingCsvImport $service)
    {
        $this->permit($request);
        $count = $service->commit($import, $request->user());

        return redirect()->route('onboarding.index')->with('status', $count.' enregistrements importés.');
    }

    public function errors(Request $request, OnboardingImport $import, OnboardingCsvImport $service)
    {
        $this->permit($request);
        abort_unless($import->created_by === $request->user()->id, 403);
        abort_unless(! $import->completed_at && $import->expires_at->gt(now()) && $import->payload !== null, 410);
        $rows = collect($service->inspect($import))->filter(fn ($row) => $row['errors'] !== []);
        $headers = OnboardingCsvImport::HEADERS[$import->kind];
        app(AuditRecorder::class)->record('onboarding.import_errors_exported', Tenant::findOrFail($import->tenant_id), [], ['kind' => $import->kind, 'row_count' => $rows->count()]);

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            $write = fn (array $row) => fputcsv($output, array_map(SpreadsheetSafeCsv::cell(...), $row), ';', '"', '');
            $write(['Ligne de données', ...$headers, 'Erreurs à corriger']);
            foreach ($rows as $row) {
                $write([$row['line'], ...array_map(fn ($header) => $row['source'][$header], $headers), implode(' | ', $row['errors'])]);
            }
            fclose($output);
        }, 'erreurs-import-'.$import->kind.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function discard(Request $request, OnboardingImport $import)
    {
        $this->permit($request);
        abort_unless($import->created_by === $request->user()->id, 403);
        DB::transaction(function () use ($import) {
            $locked = OnboardingImport::whereKey($import)->lockForUpdate()->firstOrFail();
            abort_if($locked->completed_at, 409, 'Cet import a déjà été confirmé.');
            $locked->forceFill(['payload' => null, 'expires_at' => now()])->save();
            app(AuditRecorder::class)->record('onboarding.import_discarded', Tenant::findOrFail($import->tenant_id), [], ['kind' => $import->kind, 'row_count' => $import->row_count]);
        });

        return redirect()->route('onboarding.import.index')->with('status', 'Aperçu abandonné. Les données du fichier ont été effacées.');
    }
}
