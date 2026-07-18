<?php

namespace App\Http\Controllers;

use App\Actions\Maintenance\ApproveMaintenanceOrder;
use App\Actions\Maintenance\CancelMaintenanceOrder;
use App\Actions\Maintenance\CompleteMaintenanceOrder;
use App\Actions\Maintenance\CreateMaintenanceOrder;
use App\Actions\Maintenance\RescheduleApprovedMaintenanceOrder;
use App\Actions\Maintenance\StartMaintenanceOrder;
use App\Actions\Maintenance\UpdatePlannedMaintenanceOrder;
use App\Enums\DocumentType;
use App\Http\Requests\Maintenance\CancelMaintenanceOrderRequest;
use App\Http\Requests\Maintenance\CompleteMaintenanceOrderRequest;
use App\Http\Requests\Maintenance\RescheduleMaintenanceOrderRequest;
use App\Http\Requests\Maintenance\StoreMaintenanceOrderRequest;
use App\Http\Requests\Maintenance\UpdatePlannedMaintenanceOrderRequest;
use App\Models\Agency;
use App\Models\MaintenanceOrder;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaintenanceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', MaintenanceOrder::class);
        $agency = $request->user()->agency_id;
        $scope = fn ($query) => $query->when($agency, fn ($builder) => $builder->where('agency_id', $agency));
        $now = now();
        $soon = $now->copy()->addDays(30);

        return view('maintenance.index', [
            'orders' => MaintenanceOrder::with(['vehicle:id,registration_number', 'agency:id,name'])
                ->when($agency, fn ($query) => $query->where('agency_id', $agency))
                ->when($request->string('q')->isNotEmpty(), fn ($query) => $query->where(fn ($search) => $search->where('maintenance_number', 'ilike', '%'.$request->string('q').'%')->orWhere('title', 'ilike', '%'.$request->string('q').'%')))
                ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('status')))
                ->latest()->paginate(20)->withQueryString(),
            'statuses' => ['planned', 'approved', 'in_progress', 'completed', 'cancelled'],
            'summary' => [
                'Planifiées à venir' => $scope(MaintenanceOrder::query())->whereIn('status', ['planned', 'approved'])->whereBetween('scheduled_start_at', [$now, $soon])->count(),
                'En retard' => $scope(MaintenanceOrder::query())->whereIn('status', ['planned', 'approved'])->where('scheduled_start_at', '<', $now)->count(),
                'En cours' => $scope(MaintenanceOrder::query())->where('status', 'in_progress')->count(),
                'Échéances kilométriques' => $scope(MaintenanceOrder::query())->whereNotNull('next_due_mileage')->whereHas('vehicle', fn ($query) => $query->whereColumn('vehicles.current_mileage', '>=', 'maintenance_orders.next_due_mileage'))->count(),
                'Échéances calendaires' => $scope(MaintenanceOrder::query())->whereNotNull('next_due_date')->whereDate('next_due_date', '<=', $soon)->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', MaintenanceOrder::class);
        $agency = $request->user()->agency_id;

        return view('maintenance.create', [
            'agencies' => Agency::query()->when($agency, fn ($query) => $query->whereKey($agency))->orderBy('name')->get(),
            'vehicles' => Vehicle::query()->when($agency, fn ($query) => $query->where('agency_id', $agency))->orderBy('registration_number')->get(),
        ]);
    }

    public function store(StoreMaintenanceOrderRequest $request, CreateMaintenanceOrder $action): RedirectResponse
    {
        $order = $action->handle($request->validated(), $request->user()->id);

        return redirect()->route('maintenance.show', $order)->with('status', 'Maintenance planifiée.');
    }

    public function show(Request $request, MaintenanceOrder $maintenance): View
    {
        $this->authorize('view', $maintenance);
        $maintenance->load(['agency:id,name', 'vehicle', 'histories', 'vehicleBlock', 'expenses', 'documents.currentVersion']);

        return view('maintenance.show', [
            'maintenance' => $maintenance,
            'documentTypes' => DocumentType::maintenanceTypes(),
        ]);
    }

    public function edit(Request $request, MaintenanceOrder $maintenance): View
    {
        $this->authorize('update', $maintenance);

        return view('maintenance.edit', [
            'maintenance' => $maintenance,
            'vehicles' => Vehicle::query()->where('agency_id', $maintenance->agency_id)->orderBy('registration_number')->get(),
        ]);
    }

    public function update(UpdatePlannedMaintenanceOrderRequest $request, MaintenanceOrder $maintenance, UpdatePlannedMaintenanceOrder $action): RedirectResponse
    {
        $action->handle($maintenance, $request->validated());

        return redirect()->route('maintenance.show', $maintenance)->with('status', 'Maintenance modifiée.');
    }

    public function editSchedule(Request $request, MaintenanceOrder $maintenance): View
    {
        $this->authorize('reschedule', $maintenance);

        return view('maintenance.reschedule', ['maintenance' => $maintenance]);
    }

    public function reschedule(RescheduleMaintenanceOrderRequest $request, MaintenanceOrder $maintenance, RescheduleApprovedMaintenanceOrder $action): RedirectResponse
    {
        $action->handle($maintenance, $request->validated(), $request->user()->id);

        return redirect()->route('maintenance.show', $maintenance)->with('status', 'Maintenance replanifiée avec son bloc véhicule.');
    }

    public function approve(Request $request, MaintenanceOrder $maintenance, ApproveMaintenanceOrder $action): RedirectResponse
    {
        $this->authorize('approve', $maintenance);
        $action->handle($maintenance, $request->user()->id);

        return back()->with('status', 'Maintenance approuvée et véhicule bloqué.');
    }

    public function start(Request $request, MaintenanceOrder $maintenance, StartMaintenanceOrder $action): RedirectResponse
    {
        $this->authorize('start', $maintenance);
        $action->handle($maintenance, $request->user()->id);

        return back()->with('status', 'Maintenance démarrée.');
    }

    public function complete(CompleteMaintenanceOrderRequest $request, MaintenanceOrder $maintenance, CompleteMaintenanceOrder $action): RedirectResponse
    {
        $action->handle($maintenance, $request->validated(), $request->user()->id);

        return back()->with('status', 'Maintenance terminée.');
    }

    public function cancel(CancelMaintenanceOrderRequest $request, MaintenanceOrder $maintenance, CancelMaintenanceOrder $action): RedirectResponse
    {
        $action->handle($maintenance, $request->validated('reason'), $request->user()->id);

        return back()->with('status', 'Maintenance annulée.');
    }
}
