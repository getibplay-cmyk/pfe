<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTenantSettingsRequest;
use App\Models\Tenant;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function show(Request $request): View
    {
        $tenant = Tenant::findOrFail($request->user()->tenant_id);
        $this->authorize('view', $tenant);

        return view('tenant.show', compact('tenant'));
    }

    public function update(UpdateTenantSettingsRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $tenant = Tenant::findOrFail($request->user()->tenant_id);
        $this->authorize('update', $tenant);
        $data = $request->validated();
        $old = $tenant->only(['name', 'legal_name', 'email', 'phone', 'settings']);

        $tenant->update([
            ...collect($data)->only(['name', 'legal_name', 'email', 'phone'])->all(),
            'settings' => [
                ...($tenant->settings ?? []),
                'address' => $data['address'] ?? null,
                'currency' => $data['currency'],
                'timezone' => $data['timezone'],
            ],
        ]);
        $audit->record('tenant.settings.updated', $tenant, $old, $tenant->only(array_keys($old)));

        return back()->with('status', 'Paramètres de l’entreprise mis à jour.');
    }
}
