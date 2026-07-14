<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgencyController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Agency::class);
        $query = Agency::query()->orderBy('name');

        if ($request->user()->isAgencyManager()) {
            $query->whereKey($request->user()->agency_id);
        }

        return view('agencies.index', ['agencies' => $query->paginate(20)]);
    }

    public function create(): View
    {
        $this->authorize('create', Agency::class);

        return view('agencies.form', ['agency' => new Agency]);
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $this->authorize('create', Agency::class);
        $agency = Agency::create($this->validated($request));
        $audit->record('agency.created', $agency, [], $agency->only(['code', 'name', 'is_active']));

        return redirect()->route('agencies.index')->with('status', 'Agence créée.');
    }

    public function edit(Agency $agency): View
    {
        $this->authorize('update', $agency);

        return view('agencies.form', compact('agency'));
    }

    public function update(Request $request, Agency $agency, AuditRecorder $audit): RedirectResponse
    {
        $this->authorize('update', $agency);
        $old = $agency->only(['code', 'name', 'email', 'phone', 'address', 'is_active']);
        $agency->update($this->validated($request, $agency));
        $audit->record('agency.updated', $agency, $old, $agency->only(array_keys($old)));

        return redirect()->route('agencies.index')->with('status', 'Agence mise à jour.');
    }

    public function destroy(Agency $agency, AuditRecorder $audit): RedirectResponse
    {
        $this->authorize('delete', $agency);
        $audit->record('agency.deleted', $agency, $agency->only(['code', 'name']), []);
        $agency->delete();

        return redirect()->route('agencies.index')->with('status', 'Agence archivée.');
    }

    private function validated(Request $request, ?Agency $agency = null): array
    {
        return $request->validate([
            'tenant_id' => ['prohibited'],
            'code' => ['required', 'string', 'max:30', Rule::unique('agencies')->where('tenant_id', $request->user()->tenant_id)->ignore($agency)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
