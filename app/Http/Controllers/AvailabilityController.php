<?php

namespace App\Http\Controllers;

use App\Actions\Reservations\SearchAvailableVehicles;
use App\Models\Agency;
use App\Models\Reservation;
use App\Models\VehicleCategory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AvailabilityController extends Controller
{
    public function __invoke(Request $request, SearchAvailableVehicles $search): View
    {
        $this->authorize('viewAny', Reservation::class);
        $vehicles = null;
        if ($request->filled(['agency_id', 'starts_at', 'ends_at'])) {
            $data = $request->validate([
                'tenant_id' => ['prohibited'],
                'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $request->user()->tenant_id)],
                'category_id' => ['nullable', 'integer', Rule::exists('vehicle_categories', 'id')->where('tenant_id', $request->user()->tenant_id)],
                'starts_at' => ['required', 'date'],
                'ends_at' => ['required', 'date', 'after:starts_at'],
            ]);
            abort_if($request->user()->agency_id && $request->user()->agency_id !== (int) $data['agency_id'], 403);
            $vehicles = $search->query((int) $data['agency_id'], CarbonImmutable::parse($data['starts_at']), CarbonImmutable::parse($data['ends_at']), isset($data['category_id']) ? (int) $data['category_id'] : null)->with('category')->orderBy('registration_number')->get();
        }

        return view('availability.index', [
            'vehicles' => $vehicles,
            'agencies' => Agency::query()->when($request->user()->agency_id, fn ($query, $id) => $query->whereKey($id))->orderBy('name')->get(),
            'categories' => VehicleCategory::where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
