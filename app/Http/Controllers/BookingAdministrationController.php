<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\PublicBookingProfile;
use App\Models\PublicBookingRequest;
use App\Models\PublicVehicleListing;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Support\Audit\AuditRecorder;
use App\Support\Reservations\PublicBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class BookingAdministrationController extends Controller
{
    public function settings(Request $request)
    {
        abort_unless($request->user()->isTenantOwner(), 403);
        $profile = PublicBookingProfile::first();
        $agencies = Agency::where('is_active', true)->orderBy('name')->get();
        $agencyId = (int) ($request->query('agency_id') ?? $profile?->agency_id ?? $agencies->first()?->id);
        $agency = $agencies->firstWhere('id', $agencyId);
        abort_unless($agency, 422);

        return view('booking.settings', ['profile' => $profile, 'agencies' => $agencies, 'agency' => $agency, 'vehicles' => Vehicle::where('agency_id', $agency->id)->where('operational_status', 'active')->orderBy('registration_number')->limit(500)->get(), 'published' => PublicVehicleListing::where('agency_id', $agency->id)->where('published', true)->pluck('vehicle_id')->all()]);
    }

    public function saveSettings(Request $request)
    {
        abort_unless($request->user()->isTenantOwner(), 403);
        $profile = PublicBookingProfile::first();
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['required', 'integer'], 'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('public_booking_profiles', 'slug')->ignore($profile?->id)], 'enabled' => ['sometimes', 'boolean'], 'public_name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:1500'], 'public_phone' => ['nullable', 'string', 'max:30'], 'public_email' => ['nullable', 'email:rfc', 'max:254'], 'vehicles' => ['sometimes', 'array', 'max:500'], 'vehicles.*' => ['integer', 'distinct']]);
        $agency = Agency::where('is_active', true)->findOrFail($data['agency_id']);
        $ids = array_map('intval', $data['vehicles'] ?? []);
        abort_unless(Vehicle::where('agency_id', $agency->id)->where('operational_status', 'active')->whereIn('id', $ids)->count() === count($ids), 422, __('Sélectionnez uniquement les véhicules actifs de cette agence.'));
        DB::transaction(function () use ($request, $data, $agency, $ids) {
            Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $profile = PublicBookingProfile::lockForUpdate()->first() ?? new PublicBookingProfile;
            $profile->fill([...collect($data)->only(['slug', 'public_name', 'description', 'public_phone', 'public_email'])->all(), 'agency_id' => $agency->id, 'enabled' => $request->boolean('enabled')])->save();
            PublicVehicleListing::where('profile_id', $profile->id)->update(['published' => false]);
            foreach ($ids as $id) {
                PublicVehicleListing::updateOrCreate(['vehicle_id' => $id], ['profile_id' => $profile->id, 'agency_id' => $agency->id, 'published' => true]);
            }
            app(AuditRecorder::class)->record('public_booking.settings.updated', $profile, [], ['enabled' => $profile->enabled, 'listed_vehicles' => count($ids)]);
        });

        return to_route('booking-admin.settings')->with('status', __('Catalogue mis à jour.'));
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('reservation.view') && $request->user()->hasPermission('customer.view'), 403);
        $query = PublicBookingRequest::with('vehicle')->when($request->user()->agency_id, fn ($q, $id) => $q->where('agency_id', $id));

        return view('booking.requests', ['bookings' => $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest()->paginate(20)]);
    }

    public function show(Request $request, PublicBookingRequest $booking, PublicBooking $service)
    {
        $service->authorizeReview($request->user(), $booking);
        $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $query = Customer::where('agency_id', $booking->agency_id);
        $term = trim((string) $request->query('q'));
        $query->when($term !== '', fn ($q) => $q->where(fn ($n) => $n->where('first_name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%')->orWhere('last_name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%')));

        return view('booking.request', ['booking' => $booking->load('vehicle'), 'customers' => $query->orderBy('last_name')->limit(30)->get(['id', 'customer_type', 'company_name', 'first_name', 'last_name'])]);
    }

    public function convert(Request $request, PublicBookingRequest $booking, PublicBooking $service)
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'customer_id' => ['nullable', 'integer'], 'confirmed' => ['accepted']]);
        $reservation = $service->convert($request->user(), $booking, empty($data['customer_id']) ? null : (int) $data['customer_id']);

        return to_route('reservations.edit', $reservation)->with('status', __('Brouillon créé. Vérifiez le client, sélectionnez un conducteur valide puis confirmez le tarif et la disponibilité.'));
    }

    public function reject(Request $request, PublicBookingRequest $booking, PublicBooking $service)
    {
        $service->authorizeReview($request->user(), $booking);
        abort_unless($request->user()->hasPermission('reservation.create'), 403);
        $data = $request->validate(['review_note' => ['required', 'string', 'min:5', 'max:1000']]);
        DB::transaction(function () use ($booking, $request, $data) {
            $booking = PublicBookingRequest::whereKey($booking)->lockForUpdate()->firstOrFail();
            abort_unless($booking->status === 'pending', 409);
            $booking->forceFill(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['review_note']])->save();
            app(AuditRecorder::class)->record('public_booking.rejected', $booking);
        });

        return to_route('booking-admin.index')->with('status', __('Demande classée sans suite.'));
    }
}
