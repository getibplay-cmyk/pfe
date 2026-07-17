<?php

namespace App\Http\Controllers;

use App\Actions\VehicleBlocks\CancelManualVehicleBlock;
use App\Actions\VehicleBlocks\CreateManualVehicleBlock;
use App\Actions\VehicleBlocks\ReleaseManualVehicleBlock;
use App\Enums\VehicleBlockStatus;
use App\Enums\VehicleBlockType;
use App\Enums\VehicleOperationalStatus;
use App\Http\Requests\VehicleBlocks\StoreManualVehicleBlockRequest;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VehicleBlockController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', VehicleBlock::class);

        $filters = $request->validate([
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'status' => ['nullable', Rule::enum(VehicleBlockStatus::class)],
            'type' => ['nullable', Rule::enum(VehicleBlockType::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        if ($request->user()->agency_id !== null) {
            abort_if(isset($filters['agency_id']) && (int) $filters['agency_id'] !== $request->user()->agency_id, 403);
            abort_if(isset($filters['vehicle_id']) && ! Vehicle::query()->whereKey($filters['vehicle_id'])->where('agency_id', $request->user()->agency_id)->exists(), 403);
        }

        $blocks = VehicleBlock::query()
            ->with(['agency:id,name', 'vehicle:id,registration_number,brand,model', 'creator:id,name'])
            ->when($request->user()->agency_id, fn (Builder $query, int $agencyId) => $query->where('agency_id', $agencyId))
            ->when($filters['agency_id'] ?? null, fn (Builder $query, int $agencyId) => $query->where('agency_id', $agencyId))
            ->when($filters['vehicle_id'] ?? null, fn (Builder $query, int $vehicleId) => $query->where('vehicle_id', $vehicleId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('block_type', $type))
            ->when($filters['starts_at'] ?? null, fn (Builder $query, string $startsAt) => $query->where('ends_at', '>', $startsAt))
            ->when($filters['ends_at'] ?? null, fn (Builder $query, string $endsAt) => $query->where('starts_at', '<', $endsAt))
            ->orderByDesc('starts_at')
            ->paginate(20)
            ->withQueryString();

        return view('vehicle-blocks.index', [
            'blocks' => $blocks,
            'agencies' => $this->agencies($request),
            'vehicles' => $this->vehicles($request),
            'statuses' => VehicleBlockStatus::cases(),
            'types' => VehicleBlockType::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', VehicleBlock::class);

        return view('vehicle-blocks.create', [
            'agencies' => $this->agencies($request),
            'vehicles' => $this->vehicles($request, true),
            'selectedVehicleId' => $request->integer('vehicle_id') ?: null,
        ]);
    }

    public function store(StoreManualVehicleBlockRequest $request, CreateManualVehicleBlock $action): RedirectResponse
    {
        $block = $action->handle($request->validated(), $request->user()->id);

        return redirect()->route('vehicle-blocks.index', ['vehicle_id' => $block->vehicle_id])->with('status', 'Bloc manuel créé.');
    }

    public function release(Request $request, VehicleBlock $block, ReleaseManualVehicleBlock $action): RedirectResponse
    {
        $this->authorize('update', $block);
        $action->handle($block);

        return back()->with('status', 'Bloc manuel libéré.');
    }

    public function cancel(Request $request, VehicleBlock $block, CancelManualVehicleBlock $action): RedirectResponse
    {
        $this->authorize('update', $block);
        $action->handle($block);

        return back()->with('status', 'Bloc manuel annulé.');
    }

    private function agencies(Request $request)
    {
        return Agency::query()
            ->when($request->user()->agency_id, fn (Builder $query, int $agencyId) => $query->whereKey($agencyId))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function vehicles(Request $request, bool $activeOnly = false)
    {
        return Vehicle::query()
            ->when($request->user()->agency_id, fn (Builder $query, int $agencyId) => $query->where('agency_id', $agencyId))
            ->when($activeOnly, fn (Builder $query) => $query->where('operational_status', VehicleOperationalStatus::Active))
            ->orderBy('registration_number')
            ->get(['id', 'agency_id', 'registration_number', 'brand', 'model']);
    }
}
