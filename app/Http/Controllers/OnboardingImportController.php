<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\OnboardingImport;
use App\Support\Tenancy\OnboardingCsvImport;
use Illuminate\Http\Request;
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

        return view('tenant.import', ['agencies' => Agency::where('is_active', true)->when($request->user()->agency_id, fn ($query, $id) => $query->whereKey($id))->orderBy('name')->get()]);
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
}
