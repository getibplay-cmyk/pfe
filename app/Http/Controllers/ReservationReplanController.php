<?php

namespace App\Http\Controllers;

use App\Actions\Reservations\ReplanConfirmedReservation;
use App\Exceptions\VehicleUnavailableException;
use App\Models\Reservation;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ReservationReplanController extends Controller
{
    public function edit(Request $request, Reservation $reservation, ReplanConfirmedReservation $action)
    {
        $action->authorize($request->user(), $reservation);

        return view('fleet.replan', ['reservation' => $reservation->load('vehicle'), 'vehicles' => $this->vehicles($reservation), 'preview' => null]);
    }

    public function preview(Request $request, Reservation $reservation, ReplanConfirmedReservation $action)
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'], 'vehicle_id' => ['required', 'integer', 'min:1'], 'shift_days' => ['sometimes', 'integer', 'between:-365,365', 'prohibits:starts_at,ends_at'], 'starts_at' => ['required_without:shift_days', 'date'], 'ends_at' => ['required_without:shift_days', 'date', 'after:starts_at']]);
        $preview = $action->preview($request->user(), $reservation, $data);

        return view('fleet.replan', ['reservation' => $reservation->load('vehicle'), 'vehicles' => $this->vehicles($reservation), 'preview' => $preview]);
    }

    public function confirm(Request $request, Reservation $reservation, ReplanConfirmedReservation $action)
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'proposal' => ['required', 'string', 'max:10000'], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'confirmed' => ['accepted']]);
        try {
            $replacement = $action->confirm($request->user(), $reservation, $data['proposal'], $data['reason']);
        } catch (VehicleUnavailableException) {
            throw ValidationException::withMessages(['vehicle_id' => __('Le véhicule vient d’être réservé. La réservation d’origine est conservée.')]);
        }

        return to_route('reservations.show', $replacement)->with('status', __('Réservation déplacée. Le dossier précédent est conservé dans l’historique.'));
    }

    private function vehicles(Reservation $reservation)
    {
        return Vehicle::where('agency_id', $reservation->agency_id)->where('operational_status', 'active')->orderBy('registration_number')->get(['id', 'registration_number', 'brand', 'model']);
    }
}
