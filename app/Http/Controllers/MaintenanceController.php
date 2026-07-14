<?php

namespace App\Http\Controllers;

use App\Actions\Maintenance\ApproveMaintenanceOrder;
use App\Actions\Maintenance\CancelMaintenanceOrder;
use App\Actions\Maintenance\CompleteMaintenanceOrder;
use App\Actions\Maintenance\CreateMaintenanceOrder;
use App\Actions\Maintenance\StartMaintenanceOrder;
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
            'vehicles' => Vehicle::when($agency, fn ($query) => $query->where('agency_id', $agency))->orderBy('registration_number')->get(),
        ]);
    }

    public function store(Request $request, CreateMaintenanceOrder $action): RedirectResponse
    {
        $this->permit($request, 'maintenance.create');
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['required', 'integer'], 'vehicle_id' => ['required', 'integer'], 'maintenance_type' => ['required', 'in:preventive,corrective,inspection,repair'], 'priority' => ['required', 'in:low,normal,high,critical'], 'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'scheduled_start_at' => ['nullable', 'date'], 'scheduled_end_at' => ['nullable', 'date', 'after:scheduled_start_at'], 'estimated_cost' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'], 'supplier' => ['nullable', 'string', 'max:255']]);
        $action->handle($data, $request->user()->id);

        return back()->with('status', 'Maintenance planifiée.');
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

    public function complete(Request $request, MaintenanceOrder $maintenance, CompleteMaintenanceOrder $action): RedirectResponse
    {
        $this->permitOrder($request, $maintenance, 'maintenance.complete');
        $data = $request->validate(['actual_cost' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'mileage' => ['required', 'integer', 'min:0'], 'next_due_date' => ['nullable', 'date'], 'next_due_mileage' => ['nullable', 'integer', 'min:0'], 'return_to_active' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string']]);
        $action->handle($maintenance, $data, $request->user()->id);

        return back()->with('status', 'Maintenance terminée.');
    }

    public function cancel(Request $request, MaintenanceOrder $maintenance, CancelMaintenanceOrder $action): RedirectResponse
    {
        $this->permitOrder($request, $maintenance, 'maintenance.cancel');
        $data = $request->validate(['reason' => ['required', 'string']]);
        $action->handle($maintenance, $data['reason'], $request->user()->id);

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
