<?php

namespace App\Http\Controllers;

use App\Actions\Fleet\ManageAgencyDistance;
use App\Http\Requests\ChangeAgencyDistanceStatusRequest;
use App\Http\Requests\StoreAgencyDistanceRequest;
use App\Http\Requests\UpdateAgencyDistanceRequest;
use App\Models\Agency;
use App\Models\AgencyDistance;
use App\Support\Intelligence\FleetReallocation\FleetReallocationReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgencyDistanceController extends Controller
{
    public function index(Request $request, FleetReallocationReadiness $readiness): View
    {
        $this->authorize('viewAny', AgencyDistance::class);
        $agencies = Agency::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('fleet.agency-distances.index', [
            'agencies' => $agencies,
            'distances' => AgencyDistance::query()
                ->with(['fromAgency', 'toAgency', 'verifier:id,name'])
                ->orderByDesc('active')
                ->orderBy('from_agency_id')
                ->orderBy('to_agency_id')
                ->get(),
            'readiness' => $readiness->evaluate($agencies),
            'agencyNames' => $agencies->pluck('name', 'id'),
            'canManage' => $request->user()->can('create', AgencyDistance::class),
        ]);
    }

    public function store(
        StoreAgencyDistanceRequest $request,
        ManageAgencyDistance $manage,
    ): RedirectResponse {
        $created = $manage->create($request->validated(), $request->user());

        return redirect()->route('agency-distances.index')->with(
            'status',
            $created->count() === 2
                ? 'Les deux distances directionnelles ont été enregistrées et vérifiées.'
                : 'La distance directionnelle a été enregistrée et vérifiée.',
        );
    }

    public function update(
        UpdateAgencyDistanceRequest $request,
        AgencyDistance $agencyDistance,
        ManageAgencyDistance $manage,
    ): RedirectResponse {
        $manage->correct($agencyDistance, $request->validated(), $request->user());

        return redirect()->route('agency-distances.index')
            ->with('status', 'La distance et sa provenance ont été vérifiées à nouveau.');
    }

    public function activate(
        ChangeAgencyDistanceStatusRequest $request,
        AgencyDistance $agencyDistance,
        ManageAgencyDistance $manage,
    ): RedirectResponse {
        $manage->setActive($agencyDistance, $request->user(), true);

        return redirect()->route('agency-distances.index')->with('status', 'La distance a été activée.');
    }

    public function deactivate(
        ChangeAgencyDistanceStatusRequest $request,
        AgencyDistance $agencyDistance,
        ManageAgencyDistance $manage,
    ): RedirectResponse {
        $manage->setActive($agencyDistance, $request->user(), false);

        return redirect()->route('agency-distances.index')->with('status', 'La distance a été désactivée.');
    }
}
