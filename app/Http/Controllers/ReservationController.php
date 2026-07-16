<?php

namespace App\Http\Controllers;

use App\Actions\Pricing\CalculateReservationQuote;
use App\Actions\Pricing\ResolvePricingRule;
use App\Actions\Reservations\CancelReservation;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\UpdateDraftReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\VehicleUnavailableException;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Reservation::class);
        $reservations = Reservation::with(['agency', 'customer', 'vehicle'])
            ->when($request->user()->agency_id, fn ($query, $id) => $query->where('agency_id', $id))
            ->when($request->integer('agency_id'), fn ($query, $id) => $query->where('agency_id', $id))
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->string('q')->isNotEmpty(), fn ($query) => $query->where('reservation_number', 'ilike', '%'.$request->string('q').'%'))
            ->orderByDesc('starts_at')->paginate(20)->withQueryString();

        return view('reservations.index', [
            'reservations' => $reservations,
            'statuses' => ReservationStatus::cases(),
            'agencies' => $this->agencies($request),
            'categories' => VehicleCategory::where('is_active', true)->orderBy('name')->get(),
            'vehicles' => Vehicle::query()
                ->when($request->user()->agency_id, fn ($query, $id) => $query->where('agency_id', $id))
                ->where('operational_status', 'active')
                ->orderBy('registration_number')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Reservation::class);

        return view('reservations.form', [...$this->formData($request), 'reservation' => new Reservation]);
    }

    public function store(Request $request, CreateReservation $action): RedirectResponse
    {
        $this->authorize('create', Reservation::class);
        $reservation = $action->handle($this->validated($request), $request->user()->id);

        return redirect()->route('reservations.show', $reservation)->with('status', 'Réservation brouillon créée.');
    }

    public function show(Reservation $reservation, ResolvePricingRule $resolve, CalculateReservationQuote $calculate): View
    {
        $this->authorize('view', $reservation);
        $reservation->load(['agency', 'customer', 'driver', 'vehicleCategory', 'vehicle', 'pricingRule', 'statusHistories.actor', 'vehicleBlocks', 'rentalContract']);
        $quote = null;
        $quoteError = null;
        if ($reservation->status->canBeConfirmed()) {
            try {
                $quote = $calculate->handle($resolve->handle($reservation->agency_id, $reservation->vehicle_category_id, $reservation->starts_at), $reservation->starts_at, $reservation->ends_at, $reservation->options_total);
            } catch (ValidationException $exception) {
                $quoteError = collect($exception->errors())->flatten()->first();
            }
        }

        return view('reservations.show', compact('reservation', 'quote', 'quoteError'));
    }

    public function edit(Request $request, Reservation $reservation): View
    {
        $this->authorize('update', $reservation);

        return view('reservations.form', [...$this->formData($request, $reservation), 'reservation' => $reservation]);
    }

    public function update(Request $request, Reservation $reservation, UpdateDraftReservation $action): RedirectResponse
    {
        $this->authorize('update', $reservation);
        $action->handle($reservation, $this->validated($request));

        return redirect()->route('reservations.show', $reservation)->with('status', 'Réservation mise à jour.');
    }

    public function confirm(Request $request, Reservation $reservation, ConfirmReservation $action): RedirectResponse
    {
        $this->authorize('confirm', $reservation);
        try {
            $action->handle($reservation, $request->user()->id);
        } catch (VehicleUnavailableException $exception) {
            return back()->withErrors(['vehicle_id' => $exception->getMessage()]);
        }

        return back()->with('status', 'Réservation confirmée et véhicule bloqué.');
    }

    public function cancel(Request $request, Reservation $reservation, CancelReservation $action): RedirectResponse
    {
        $this->authorize('cancel', $reservation);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'reason' => ['required', 'string', 'max:2000']]);
        $action->handle($reservation, $data['reason'], $request->user()->id);

        return back()->with('status', 'Réservation annulée et bloc libéré.');
    }

    private function validated(Request $request): array
    {
        $tenantId = $request->user()->tenant_id;
        $agencyId = $request->integer('agency_id');
        $customerId = $request->integer('customer_id');
        $categoryId = $request->integer('vehicle_category_id');
        $data = $request->validate([
            'tenant_id' => ['prohibited'],
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('agency_id', $agencyId))],
            'driver_id' => ['nullable', 'integer', Rule::exists('drivers', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('customer_id', $customerId))],
            'vehicle_category_id' => ['required', 'integer', Rule::exists('vehicle_categories', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('agency_id', $agencyId)->where('vehicle_category_id', $categoryId))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'status' => ['required', Rule::enum(ReservationStatus::class), Rule::in(['draft', 'pending'])],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        abort_if($request->user()->agency_id && $request->user()->agency_id !== (int) $data['agency_id'], 403);

        return $data;
    }

    private function formData(Request $request, ?Reservation $reservation = null): array
    {
        $agencies = $this->agencies($request);
        $selectedAgencyId = $request->user()->agency_id
            ?? $request->integer('agency_id')
            ?: $reservation?->agency_id
            ?? $agencies->first()?->id;

        if (! $agencies->contains('id', $selectedAgencyId)) {
            abort(403, 'Cette agence ne fait pas partie du contexte actif.');
        }

        return [
            'agencies' => $agencies,
            'selectedAgencyId' => $selectedAgencyId,
            'customers' => Customer::query()->where('agency_id', $selectedAgencyId)->with('drivers')->orderBy('last_name')->get(),
            'categories' => VehicleCategory::where('is_active', true)->orderBy('name')->get(),
            'vehicles' => Vehicle::query()->where('agency_id', $selectedAgencyId)->where('operational_status', 'active')->orderBy('registration_number')->get(),
        ];
    }

    private function agencies(Request $request)
    {
        return Agency::query()->when($request->user()->agency_id, fn ($query, $id) => $query->whereKey($id))->orderBy('name')->get();
    }
}
