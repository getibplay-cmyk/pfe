<?php

namespace App\Http\Controllers;

use App\Enums\VehicleOperationalStatus;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Tenancy\AgencyAccess;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FleetPlanningController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, AgencyAccess $access): View
    {
        $this->authorize('viewAny', Vehicle::class);
        $filters = $request->validate([
            'tenant_id' => ['prohibited'],
            'agency_id' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', Rule::in([7, 14, 28])],
            'q' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'integer', Rule::exists('vehicle_categories', 'id')->where('tenant_id', $context->tenantId())->whereNull('deleted_at')],
            'status' => ['nullable', Rule::enum(VehicleOperationalStatus::class)],
        ]);
        $agencyId = $request->filled('agency_id') ? $access->required($filters['agency_id']) : $context->agencyId();
        $timezone = (string) ($request->user()->tenant->settings['timezone'] ?? config('app.timezone'));
        $start = CarbonImmutable::parse($filters['date'] ?? 'today', $timezone)->startOfDay();
        $days = (int) ($filters['days'] ?? 7);
        $end = $start->addDays($days);
        $canMove = $request->user()->hasPermission('reservation.view') && $request->user()->hasPermission('reservation.create') && $request->user()->hasPermission('reservation.cancel') && $request->user()->hasPermission('reservation.confirm');
        $vehicles = Vehicle::query()->with('agency:id,name')
            ->when($agencyId, fn ($query) => $query->where('agency_id', $agencyId))
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('vehicle_category_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('operational_status', $status))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where('registration_number', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%'))
            ->with(['blocks' => fn ($query) => $query->where('status', 'active')
                ->when($canMove, fn ($q) => $q->with('reservation'))
                ->when($agencyId, fn ($query) => $query->where('agency_id', $agencyId))
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->orderBy('starts_at')])
            ->orderBy('registration_number')->paginate(20)->withQueryString();

        return view('fleet.planning', [
            'vehicles' => $vehicles, 'start' => $start, 'end' => $end, 'days' => $days,
            'agencyId' => $agencyId,
            'canMove' => $canMove,
            'categories' => VehicleCategory::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => VehicleOperationalStatus::cases(),
            'agencies' => Agency::query()->when($context->agencyId(), fn ($query, $id) => $query->whereKey($id))->orderBy('name')->get(['id', 'name']),
            'dates' => collect(range(0, $days - 1))->map(fn ($offset) => $start->addDays($offset)),
        ]);
    }
}
