<?php

namespace App\Support\Reservations;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Pricing\CalculateReservationQuote;
use App\Actions\Pricing\ResolvePricingRule;
use App\Actions\Reservations\CreateReservation;
use App\Models\Customer;
use App\Models\PublicBookingProfile;
use App\Models\PublicBookingRequest;
use App\Models\PublicVehicleListing;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use App\Support\Contracts\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class PublicBooking
{
    public function listing(PublicBookingProfile $profile, string $id): PublicVehicleListing
    {
        return PublicVehicleListing::where('profile_id', $profile->id)->where('agency_id', $profile->agency_id)->where('published', true)
            ->whereHas('vehicle', fn ($q) => $q->where('operational_status', 'active')->whereHas('category', fn ($category) => $category->where('is_active', true)))
            ->with('vehicle.category')->findOrFail($id);
    }

    public function quote(PublicBookingProfile $profile, PublicVehicleListing $listing, array $data, string $sessionId): array
    {
        [$start, $end] = app(ReservationPeriodValidator::class)->future($data['starts_at'], $data['ends_at']);
        $vehicle = $listing->vehicle;
        $available = ! VehicleBlock::where('vehicle_id', $vehicle->id)->where('status', 'active')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
        $price = app(CalculateReservationQuote::class)->handle(app(ResolvePricingRule::class)->handle($profile->agency_id, $vehicle->vehicle_category_id, $start), $start, $end);
        $payload = ['tenant' => $profile->tenant_id, 'profile' => $profile->id, 'listing' => $listing->id, 'session' => hash('sha256', $sessionId), 'request' => (string) Str::uuid(), 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'price' => app(CanonicalJson::class)->hash($price), 'expires' => now()->addMinutes(20)->timestamp];

        return compact('price', 'start', 'end', 'available') + ['proposal' => $available ? Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)) : null];
    }

    public function submit(PublicBookingProfile $profile, string $listingId, string $proposal, array $contact, string $sessionId): PublicBookingRequest
    {
        try {
            $payload = json_decode(Crypt::decryptString($proposal), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, __('Recalculez votre devis avant d’envoyer la demande.'));
        }
        abort_unless(($payload['tenant'] ?? null) === $profile->tenant_id && ($payload['profile'] ?? null) === $profile->id && ($payload['listing'] ?? null) === $listingId && ($payload['session'] ?? null) === hash('sha256', $sessionId), 403);

        return DB::transaction(function () use ($profile, $listingId, $payload, $contact, $sessionId) {
            $profile = PublicBookingProfile::whereKey($profile)->where('enabled', true)->lockForUpdate()->firstOrFail();
            $listing = $this->listing($profile, $listingId);
            $existing = PublicBookingRequest::whereKey($payload['request'])->where('session_hash', hash('sha256', $sessionId))->first();
            if ($existing) {
                return $existing;
            }
            abort_if($payload['expires'] < now()->timestamp, 409, __('Le devis a expiré. Recalculez-le avec les mêmes dates.'));
            $quote = $this->quote($profile, $listing, $payload, $sessionId);
            abort_unless($quote['available'], 409, __('Ce véhicule n’est plus disponible sur ces dates.'));
            abort_unless(hash_equals($payload['price'], app(CanonicalJson::class)->hash($quote['price'])), 409, __('Le tarif a changé. Recalculez votre devis.'));
            $booking = new PublicBookingRequest(['agency_id' => $profile->agency_id, 'profile_id' => $profile->id, 'listing_id' => $listing->id, 'vehicle_id' => $listing->vehicle_id, 'starts_at' => $quote['start'], 'ends_at' => $quote['end'], 'contact' => $contact, 'quote_snapshot' => $quote['price'], 'session_hash' => hash('sha256', $sessionId), 'status' => 'pending']);
            $booking->id = $payload['request'];
            $booking->save();
            app(AuditRecorder::class)->record('public_booking.requested', $booking, [], ['vehicle_id' => $listing->vehicle_id]);

            return $booking;
        });
    }

    public function authorizeReview(User $user, PublicBookingRequest $booking): void
    {
        abort_unless($user->tenant_id === $booking->tenant_id && ($user->agency_id === null || $user->agency_id === $booking->agency_id)
            && $user->hasPermission('reservation.view') && $user->hasPermission('customer.view'), 403);
    }

    public function convert(User $user, PublicBookingRequest $booking, ?int $customerId): Reservation
    {
        $this->authorizeReview($user, $booking);
        Gate::forUser($user)->authorize('create', Reservation::class);

        return DB::transaction(function () use ($user, $booking, $customerId) {
            $booking = PublicBookingRequest::whereKey($booking)->lockForUpdate()->firstOrFail();
            if ($booking->status === 'converted') {
                return Reservation::findOrFail($booking->reservation_id);
            }
            abort_unless($booking->status === 'pending', 409);
            app(ReservationPeriodValidator::class)->future($booking->starts_at, $booking->ends_at);
            abort_if(VehicleBlock::where('vehicle_id', $booking->vehicle_id)->where('status', 'active')->where('starts_at', '<', $booking->ends_at)->where('ends_at', '>', $booking->starts_at)->exists(), 409, __('Ce véhicule est maintenant occupé. Contactez le demandeur pour d’autres dates.'));
            if ($customerId !== null) {
                $customer = Customer::where('agency_id', $booking->agency_id)->findOrFail($customerId);
                Gate::forUser($user)->authorize('view', $customer);
            } else {
                Gate::forUser($user)->authorize('create', Customer::class);
                $customer = app(CreateCustomer::class)->handle(['agency_id' => $booking->agency_id, 'customer_type' => 'individual', ...collect($booking->contact)->only(['first_name', 'last_name', 'email', 'phone'])->all()]);
            }
            $reservation = app(CreateReservation::class)->handle(['agency_id' => $booking->agency_id, 'customer_id' => $customer->id, 'vehicle_category_id' => $booking->vehicle->vehicle_category_id, 'vehicle_id' => $booking->vehicle_id, 'starts_at' => $booking->starts_at, 'ends_at' => $booking->ends_at, 'status' => 'draft'], $user->id);
            $booking->forceFill(['status' => 'converted', 'reservation_id' => $reservation->id, 'reviewed_by' => $user->id, 'reviewed_at' => now()])->save();
            app(AuditRecorder::class)->record('public_booking.converted', $booking, [], ['reservation_id' => $reservation->id]);

            return $reservation;
        });
    }
}
