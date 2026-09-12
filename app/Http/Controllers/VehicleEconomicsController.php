<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Vehicle;
use App\Models\VehicleEconomicProfile;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VehicleEconomicsController extends Controller
{
    public function show(Request $request, Vehicle $vehicle)
    {
        $this->authorize('view', $vehicle);
        abort_unless($request->user()->hasPermission('report.view') && $request->user()->hasPermission('expense.view'), 403);
        $profiles = VehicleEconomicProfile::where('vehicle_id', $vehicle->id)->orderByDesc('revision')->paginate(10);

        return view('vehicles.economics', ['vehicle' => $vehicle, 'profiles' => $profiles, 'profile' => VehicleEconomicProfile::where('vehicle_id', $vehicle->id)->latest('revision')->first(), 'acquisitions' => Expense::where('agency_id', $vehicle->agency_id)->where('vehicle_id', $vehicle->id)->where('status', 'approved')->where('category', 'other')->latest('expense_date')->limit(100)->get(['id', 'expense_number', 'amount', 'currency', 'expense_date']), 'canManage' => $request->user()->can('update', $vehicle) && $request->user()->hasPermission('expense.approve')]);
    }

    public function store(Request $request, Vehicle $vehicle)
    {
        $this->authorize('update', $vehicle);
        abort_unless($request->user()->hasPermission('report.view') && $request->user()->hasPermission('expense.view') && $request->user()->hasPermission('expense.approve'), 403);
        $money = ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'];
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'], 'revision' => ['required', 'integer', 'min:0'], 'effective_from' => ['required', 'date_format:Y-m-d'], 'acquired_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:effective_from'], 'acquisition_cost' => $money, 'residual_value' => $money, 'depreciation_months' => ['required', 'integer', 'between:1,240'], 'annual_insurance' => $money, 'monthly_unrecorded_costs' => $money, 'currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'acquisition_expense_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'no_duplicate_costs' => ['accepted']]);
        if (DecimalMoney::toMinorUnits($data['residual_value']) > DecimalMoney::toMinorUnits($data['acquisition_cost'])) {
            throw ValidationException::withMessages(['residual_value' => __('La valeur résiduelle ne peut pas dépasser le coût d’achat.')]);
        }
        DB::transaction(function () use ($request, $vehicle, $data) {
            $vehicle = Vehicle::whereKey($vehicle)->lockForUpdate()->firstOrFail();
            $last = VehicleEconomicProfile::where('vehicle_id', $vehicle->id)->orderByDesc('revision')->first();
            abort_unless((int) ($last?->revision ?? 0) === (int) $data['revision'], 409, __('Les hypothèses ont changé. Rechargez avant d’enregistrer.'));
            if ($last && $data['effective_from'] < $last->effective_from->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => __('La nouvelle période doit commencer à la date de la dernière version ou après.')]);
            }
            if (! empty($data['acquisition_expense_id'])) {
                Expense::whereKey($data['acquisition_expense_id'])->where('agency_id', $vehicle->agency_id)->where('vehicle_id', $vehicle->id)->where('currency', $data['currency'])->where('category', 'other')->where('status', 'approved')->firstOrFail();
            }
            $profile = VehicleEconomicProfile::create([...collect($data)->except(['revision', 'no_duplicate_costs'])->all(), 'agency_id' => $vehicle->agency_id, 'vehicle_id' => $vehicle->id, 'revision' => ($last?->revision ?? 0) + 1, 'created_by' => $request->user()->id]);
            app(AuditRecorder::class)->record('vehicle.economics.versioned', $profile, [], ['vehicle_id' => $vehicle->id, 'revision' => $profile->revision, 'effective_from' => $profile->effective_from->toDateString()]);
        });

        return to_route('vehicle-economics.show', $vehicle)->with('status', __('Nouvelle version des hypothèses enregistrée.'));
    }
}
