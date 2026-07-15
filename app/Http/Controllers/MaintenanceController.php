<?php

namespace App\Http\Controllers;

use App\Actions\Maintenance\ApproveMaintenanceOrder;
use App\Actions\Maintenance\CancelMaintenanceOrder;
use App\Actions\Maintenance\CompleteMaintenanceOrder;
use App\Actions\Maintenance\CreateMaintenanceOrder;
use App\Actions\Maintenance\StartMaintenanceOrder;
use App\Http\Requests\Maintenance\CancelMaintenanceOrderRequest;
use App\Http\Requests\Maintenance\CompleteMaintenanceOrderRequest;
use App\Http\Requests\Maintenance\StoreMaintenanceOrderRequest;
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
        $this->permit($request, 'maintenance.view');
        $agency = $request->user()->agency_id;

        return view('maintenance.index', [
            'orders' => MaintenanceOrder::with('vehicle')->when($agency, fn ($query) => $query->where('agency_id', $agency))->latest()->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $this->permit($request, 'maintenance.create');
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
        $this->permitOrder($request, $maintenance, 'maintenance.view');
        $maintenance->load(['vehicle', 'histories', 'vehicleBlock', 'expenses']);

        return view('maintenance.show', ['maintenance' => $maintenance]);
    }

    public function approve(Request $request, MaintenanceOrder $maintenance, ApproveMaintenanceOrder $action): RedirectResponse
    {
        $this->permitOrder($request, $maintenance, 'maintenance.approve');
        $action->handle($maintenance, $request->user()->id);

        return back()->with('status', 'Maintenance approuvée et véhicule bloqué.');
    }

    public function start(Request $request, MaintenanceOrder $maintenance, StartMaintenanceOrder $action): RedirectResponse
    {
        $this->permitOrder($request, $maintenance, 'maintenance.start');
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

    private function permit(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }

    private function permitOrder(Request $request, MaintenanceOrder $order, string $permission): void
    {
        $this->permit($request, $permission);
        abort_if($request->user()->agency_id && $request->user()->agency_id !== $order->agency_id, 403);
    }
}
