<?php

namespace App\Http\Controllers;

use App\Actions\Vehicles\ChangeVehicleOperationalStatus;
use App\Actions\Vehicles\CreateVehicle;
use App\Actions\Vehicles\UpdateVehicle;
use App\Enums\VehicleOperationalStatus;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VehicleController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Vehicle::class);
        $vehicles = Vehicle::with(['agency', 'category'])->when($request->user()->isAgencyManager(), fn ($q) => $q->where('agency_id', $request->user()->agency_id))->when($request->integer('agency_id'), fn ($q, $id) => $q->where('agency_id', $id))->when($request->integer('category_id'), fn ($q, $id) => $q->where('vehicle_category_id', $id))->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('operational_status', $request->string('status')))->orderBy('registration_number')->paginate(20)->withQueryString();

        return view('vehicles.index', ['vehicles' => $vehicles, 'agencies' => Agency::orderBy('name')->get(), 'categories' => VehicleCategory::orderBy('name')->get(), 'statuses' => VehicleOperationalStatus::cases()]);
    }

    public function create(): View
    {
        $this->authorize('create', Vehicle::class);

        return view('vehicles.form', $this->formData(new Vehicle));
    }

    public function store(Request $request, CreateVehicle $action): RedirectResponse
    {
        $this->authorize('create', Vehicle::class);
        $vehicle = $action->handle($this->validated($request), $request->user()->id);

        return redirect()->route('vehicles.show', $vehicle)->with('status', 'Véhicule créé.');
    }

    public function show(Vehicle $vehicle): View
    {
        $this->authorize('view', $vehicle);
        $vehicle->load(['agency', 'category', 'statusHistories', 'documents.currentVersion']);

        return view('vehicles.show', compact('vehicle'));
    }

    public function edit(Vehicle $vehicle): View
    {
        $this->authorize('update', $vehicle);

        return view('vehicles.form', $this->formData($vehicle));
    }

    public function update(Request $request, Vehicle $vehicle, UpdateVehicle $action): RedirectResponse
    {
        $this->authorize('update', $vehicle);
        $action->handle($vehicle, $this->validated($request, $vehicle));

        return redirect()->route('vehicles.show', $vehicle)->with('status', 'Véhicule mis à jour.');
    }

    public function changeStatus(Request $request, Vehicle $vehicle, ChangeVehicleOperationalStatus $action): RedirectResponse
    {
        $this->authorize('update', $vehicle);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'operational_status' => ['required', Rule::enum(VehicleOperationalStatus::class)], 'reason' => ['nullable', 'max:2000']]);
        $action->handle($vehicle, VehicleOperationalStatus::from($data['operational_status']), $data['reason'] ?? null, $request->user()->id);

        return back()->with('status', 'Statut opérationnel mis à jour.');
    }

    private function formData(Vehicle $vehicle): array
    {
        return ['vehicle' => $vehicle, 'agencies' => Agency::orderBy('name')->get(), 'categories' => VehicleCategory::where('is_active', true)->orderBy('name')->get()];
    }

    private function validated(Request $request, ?Vehicle $vehicle = null): array
    {
        return $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['required', 'integer'], 'vehicle_category_id' => ['required', 'integer'], 'registration_number' => ['required', 'max:50', Rule::unique('vehicles')->where('tenant_id', $request->user()->tenant_id)->ignore($vehicle)], 'vin' => ['nullable', 'max:100', Rule::unique('vehicles')->where('tenant_id', $request->user()->tenant_id)->ignore($vehicle)], 'brand' => ['required', 'max:100'], 'model' => ['required', 'max:100'], 'production_year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)], 'fuel_type' => ['required', Rule::in(['petrol', 'diesel', 'hybrid', 'electric', 'other'])], 'transmission' => ['required', Rule::in(['manual', 'automatic'])], 'color' => ['nullable', 'max:50'], 'current_mileage' => ['required', 'integer', 'min:0'], 'first_registration_date' => ['nullable', 'date']]);
    }
}
