<?php

namespace App\Http\Controllers;

use App\Actions\Rentals\CompleteDepartureInspection;
use App\Actions\Rentals\CompleteReturnInspection;
use App\Enums\InspectionItemCondition;
use App\Models\RentalContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleInspectionController extends Controller
{
    public function departure(Request $request, RentalContract $contract, CompleteDepartureInspection $action): RedirectResponse
    {
        $this->authorize('view', $contract);
        abort_unless($request->user()->hasPermission('inspection.manage'), 403);
        $action->handle($contract, $this->validated($request), $request->user()->id);

        return back()->with('status', 'Inspection de départ terminée.');
    }

    public function return(Request $request, RentalContract $contract, CompleteReturnInspection $action): RedirectResponse
    {
        $this->authorize('view', $contract);
        abort_unless($request->user()->hasPermission('inspection.manage'), 403);
        $action->handle($contract, $this->validated($request), $request->user()->id);

        return back()->with('status', 'Inspection de retour terminée et comparaison calculée.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'tenant_id' => ['prohibited'], 'mileage' => ['required', 'integer', 'min:0'], 'fuel_level' => ['required', 'decimal:0,2', 'between:0,100'], 'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'], 'items.*.item_code' => ['required', 'alpha_dash', 'max:50'], 'items.*.label' => ['required', 'string', 'max:120'], 'items.*.condition' => ['required', Rule::enum(InspectionItemCondition::class)], 'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
