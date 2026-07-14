<?php

namespace App\Http\Controllers;

use App\Actions\Rentals\ReportVehicleDamage;
use App\Actions\Rentals\ReviewDamageResponsibility;
use App\Enums\DamageResponsibility;
use App\Enums\DamageSeverity;
use App\Enums\DamageStatus;
use App\Models\DamageReport;
use App\Models\RentalContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DamageReportController extends Controller
{
    public function store(Request $request, RentalContract $contract, ReportVehicleDamage $action): RedirectResponse
    {
        $this->authorize('view', $contract);
        abort_unless($request->user()->hasPermission('damage.report'), 403);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'return_inspection_id' => ['required', 'integer'], 'description' => ['required', 'string', 'max:5000'], 'vehicle_area' => ['nullable', 'string', 'max:255'], 'severity' => ['required', Rule::enum(DamageSeverity::class)], 'estimated_cost' => ['nullable', 'decimal:0,2', 'min:0']]);
        $action->handle($contract, $data, $request->user()->id);

        return back()->with('status', 'Dommage signalé. La responsabilité reste à décider humainement.');
    }

    public function review(Request $request, DamageReport $damage, ReviewDamageResponsibility $action): RedirectResponse
    {
        $this->authorize('review', $damage);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'responsibility' => ['required', Rule::enum(DamageResponsibility::class)], 'status' => ['required', Rule::enum(DamageStatus::class)], 'approved_cost' => ['nullable', 'decimal:0,2', 'min:0'], 'reason' => ['required', 'string', 'max:2000']]);
        $action->handle($damage, $data, $request->user()->id);

        return back()->with('status', 'Responsabilité et traitement du dommage enregistrés.');
    }
}
