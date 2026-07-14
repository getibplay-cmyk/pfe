<?php

namespace App\Http\Controllers;

use App\Models\VehicleCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VehicleCategoryController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', VehicleCategory::class);

        return view('vehicle-categories.index', ['categories' => VehicleCategory::orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        $this->authorize('create', VehicleCategory::class);

        return view('vehicle-categories.form', ['category' => new VehicleCategory]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', VehicleCategory::class);
        VehicleCategory::create($this->validated($request));

        return redirect()->route('vehicle-categories.index')->with('status', 'Catégorie créée.');
    }

    public function edit(VehicleCategory $vehicleCategory): View
    {
        $this->authorize('update', $vehicleCategory);

        return view('vehicle-categories.form', ['category' => $vehicleCategory]);
    }

    public function update(Request $request, VehicleCategory $vehicleCategory): RedirectResponse
    {
        $this->authorize('update', $vehicleCategory);
        $vehicleCategory->update($this->validated($request, $vehicleCategory));

        return redirect()->route('vehicle-categories.index')->with('status', 'Catégorie mise à jour.');
    }

    public function destroy(VehicleCategory $vehicleCategory): RedirectResponse
    {
        $this->authorize('delete', $vehicleCategory);
        $vehicleCategory->delete();

        return back()->with('status', 'Catégorie archivée.');
    }

    private function validated(Request $request, ?VehicleCategory $category = null): array
    {
        return $request->validate(['tenant_id' => ['prohibited'], 'code' => ['required', 'max:30', Rule::unique('vehicle_categories')->where('tenant_id', $request->user()->tenant_id)->ignore($category)], 'name' => ['required', 'max:255'], 'acriss_code' => ['nullable', 'max:10'], 'seats' => ['nullable', 'integer', 'between:1,100'], 'doors' => ['nullable', 'integer', 'between:1,20'], 'luggage_capacity' => ['nullable', 'integer', 'between:0,100'], 'description' => ['nullable', 'max:2000'], 'is_active' => ['required', 'boolean']]);
    }
}
