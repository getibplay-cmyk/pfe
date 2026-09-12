<?php

namespace App\Actions\Reservations;

use App\Actions\Pricing\CalculateReservationQuote;
use App\Actions\Pricing\ResolvePricingRule;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use App\Support\Contracts\CanonicalJson;
use App\Support\Reservations\ReservationPeriodValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReplanConfirmedReservation
{
    public function authorize(User $user, Reservation $reservation): void
    {
        Gate::forUser($user)->authorize('view', $reservation);
        Gate::forUser($user)->authorize('cancel', $reservation);
        Gate::forUser($user)->authorize('create', Reservation::class);
        abort_unless($user->hasPermission('reservation.confirm'), 403);
        abort_unless($reservation->status === ReservationStatus::Confirmed && ! $reservation->rentalContract()->exists(), 409, __('Seules les réservations confirmées sans contrat peuvent être déplacées ici.'));
    }

    public function preview(User $user, Reservation $reservation, array $data): array
    {
        $this->authorize($user, $reservation);
        $timezone = $user->tenant->settings['timezone'] ?? config('app.timezone');
        if (array_key_exists('shift_days', $data)) {
            $data['starts_at'] = $reservation->starts_at->setTimezone($timezone)->addDays((int) $data['shift_days']);
            $data['ends_at'] = $reservation->ends_at->setTimezone($timezone)->addDays((int) $data['shift_days']);
        } else {
            $data['starts_at'] = CarbonImmutable::parse($data['starts_at'], $timezone);
            $data['ends_at'] = CarbonImmutable::parse($data['ends_at'], $timezone);
        }
        [$start, $end] = app(ReservationPeriodValidator::class)->future($data['starts_at'], $data['ends_at']);
        $vehicle = Vehicle::whereKey($data['vehicle_id'])->where('agency_id', $reservation->agency_id)->firstOrFail();
        Gate::forUser($user)->authorize('view', $vehicle);
        if ($vehicle->operational_status->value !== 'active') {
            throw ValidationException::withMessages(['vehicle_id' => __('Le véhicule doit être actif.')]);
        }
        $quote = app(CalculateReservationQuote::class)->handle(app(ResolvePricingRule::class)->handle($vehicle->agency_id, $vehicle->vehicle_category_id, $start), $start, $end, $reservation->options_total ?? '0.00');
        if ($quote['currency'] !== $reservation->currency) {
            throw ValidationException::withMessages(['vehicle_id' => __('Le nouveau tarif doit utiliser la même devise que la réservation.')]);
        }
        $conflicts = VehicleBlock::where('vehicle_id', $vehicle->id)->where('status', 'active')
            ->whereNotIn('id', $reservation->vehicleBlocks()->select('id'))
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->orderBy('starts_at')->limit(10)->get(['block_type', 'starts_at', 'ends_at']);
        $proposal = ['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'reservation_id' => $reservation->id, 'request_id' => (string) Str::uuid(), 'state' => $this->state($reservation), 'vehicle_id' => $vehicle->id, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'quote_hash' => $this->quoteHash($quote), 'expires_at' => now()->addMinutes(10)->timestamp];

        return compact('vehicle', 'start', 'end', 'quote', 'conflicts') + ['token' => $conflicts->isEmpty() ? Crypt::encryptString(json_encode($proposal, JSON_THROW_ON_ERROR)) : null];
    }

    public function confirm(User $user, Reservation $reservation, string $token, string $reason): Reservation
    {
        Gate::forUser($user)->authorize('view', $reservation);
        try {
            $proposal = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, __('La proposition est invalide. Préparez un nouvel aperçu.'));
        }
        abort_unless(is_array($proposal) && ($proposal['tenant_id'] ?? null) === $user->tenant_id && ($proposal['user_id'] ?? null) === $user->id && ($proposal['reservation_id'] ?? null) === $reservation->id, 403);

        return DB::transaction(function () use ($user, $reservation, $proposal, $reason) {
            $locked = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
            $previous = DB::table('reservation_replans')->where('tenant_id', $user->tenant_id)->where('previous_reservation_id', $locked->id)->first();
            if ($previous) {
                abort_unless($previous->request_id === $proposal['request_id'] && (int) $previous->confirmed_by === $user->id, 409, __('Cette réservation a déjà été déplacée.'));
                $replacement = Reservation::findOrFail($previous->replacement_reservation_id);
                Gate::forUser($user)->authorize('view', $replacement);

                return $replacement;
            }
            abort_if(($proposal['expires_at'] ?? 0) < now()->timestamp, 409, __('La proposition a expiré. Préparez un nouvel aperçu.'));
            $this->authorize($user, $locked);
            abort_unless(hash_equals($proposal['state'], $this->state($locked)), 409, __('La réservation a changé depuis l’aperçu.'));
            $fresh = $this->preview($user, $locked, $proposal);
            abort_unless($fresh['conflicts']->isEmpty(), 409, __('Le véhicule est maintenant occupé sur cette période.'));
            abort_unless(hash_equals($proposal['quote_hash'], $this->quoteHash($fresh['quote'])), 409, __('Le tarif a changé depuis l’aperçu. Vérifiez le nouveau montant.'));
            app(CancelReservation::class)->handle($locked, $reason, $user->id);
            $replacement = app(CreateReservation::class)->handle([
                'agency_id' => $locked->agency_id, 'customer_id' => $locked->customer_id, 'driver_id' => $locked->driver_id,
                'vehicle_category_id' => $fresh['vehicle']->vehicle_category_id, 'vehicle_id' => $fresh['vehicle']->id,
                'starts_at' => $fresh['start'], 'ends_at' => $fresh['end'], 'status' => 'draft', 'notes' => $locked->notes,
            ], $user->id);
            $replacement->forceFill(['options_total' => $locked->options_total ?? '0.00'])->save();
            $replacement = app(ConfirmReservation::class)->handle($replacement, $user->id);
            abort_unless(hash_equals($proposal['quote_hash'], $this->quoteHash($replacement->only(array_keys($fresh['quote'])))), 409, __('Le tarif a changé pendant la confirmation. Recommencez depuis l’aperçu.'));
            DB::table('reservation_replans')->insert(['tenant_id' => $user->tenant_id, 'agency_id' => $locked->agency_id, 'previous_reservation_id' => $locked->id, 'replacement_reservation_id' => $replacement->id, 'request_id' => $proposal['request_id'], 'reason' => $reason, 'confirmed_by' => $user->id, 'created_at' => now()]);
            app(AuditRecorder::class)->record('reservation.replanned', $replacement, [], ['previous_reservation_id' => $locked->id]);

            return $replacement;
        }, 3);
    }

    private function state(Reservation $reservation): string
    {
        return hash('sha256', json_encode([$reservation->id, $reservation->tenant_id, $reservation->agency_id, $reservation->customer_id, $reservation->driver_id, $reservation->vehicle_id, $reservation->status->value, $reservation->starts_at->toIso8601String(), $reservation->ends_at->toIso8601String(), $reservation->total_amount, $reservation->options_total, $reservation->updated_at->format('Y-m-d H:i:s.uP')], JSON_THROW_ON_ERROR));
    }

    private function quoteHash(array $quote): string
    {
        $snapshot = $quote['pricing_snapshot'];
        foreach (['starts_at', 'ends_at'] as $key) {
            $snapshot['period'][$key] = CarbonImmutable::parse($snapshot['period'][$key])->utc()->toIso8601String();
        }

        return app(CanonicalJson::class)->hash($snapshot);
    }
}
