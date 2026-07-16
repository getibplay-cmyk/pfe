<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\UpdateAgency;
use App\Http\Requests\StoreAgencyRequest;
use App\Http\Requests\UpdateAgencyRequest;
use App\Models\Agency;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AgencyController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Agency::class);
        $query = Agency::query()->orderBy('name');

        if ($request->user()->agency_id !== null) {
            $query->whereKey($request->user()->agency_id);
        }

        return view('agencies.index', ['agencies' => $query->paginate(20)]);
    }

    public function create(): View
    {
        $this->authorize('create', Agency::class);

        return view('agencies.form', ['agency' => new Agency]);
    }

    public function store(StoreAgencyRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $agency = Agency::create([...$request->validated(), 'is_active' => true]);
        $audit->record('agency.created', $agency, [], $agency->only(['code', 'name', 'is_active']));

        return redirect()->route('agencies.index')->with('status', 'Agence créée.');
    }

    public function show(Agency $agency): View
    {
        $this->authorize('view', $agency);

        return view('agencies.show', [
            'agency' => $agency,
            'users' => User::query()->where('tenant_id', $agency->tenant_id)->where('agency_id', $agency->id)->with('role')->orderBy('name')->get(),
            'counts' => [
                'Véhicules' => DB::table('vehicles')->where('tenant_id', $agency->tenant_id)->where('agency_id', $agency->id)->whereNull('deleted_at')->count(),
                'Réservations' => DB::table('reservations')->where('tenant_id', $agency->tenant_id)->where('agency_id', $agency->id)->whereNull('deleted_at')->count(),
                'Contrats' => DB::table('rental_contracts')->where('tenant_id', $agency->tenant_id)->where('agency_id', $agency->id)->whereNull('deleted_at')->count(),
                'Maintenances' => DB::table('maintenance_orders')->where('tenant_id', $agency->tenant_id)->where('agency_id', $agency->id)->whereNull('deleted_at')->count(),
            ],
        ]);
    }

    public function edit(Agency $agency): View
    {
        $this->authorize('update', $agency);

        return view('agencies.form', compact('agency'));
    }

    public function update(UpdateAgencyRequest $request, Agency $agency, UpdateAgency $action): RedirectResponse
    {
        $action->handle($agency, $request->validated(), $request->user());

        return redirect()->route('agencies.index')->with('status', 'Agence mise à jour.');
    }

    /**
     * Backward-compatible endpoint: an agency is deactivated, never deleted.
     */
    public function destroy(Request $request, Agency $agency, UpdateAgency $action): RedirectResponse
    {
        $this->authorize('delete', $agency);
        $action->handle($agency, [
            ...$agency->only(['code', 'name', 'email', 'phone', 'address']),
            'is_active' => false,
        ], $request->user());

        return redirect()->route('agencies.index')->with('status', 'Agence désactivée.');
    }
}
