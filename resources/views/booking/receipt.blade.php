<x-booking-layout :profile="$profile" :title="__('Votre demande est enregistrée')">
    <x-flash-message type="success" :message="__('L’agence dispose de votre demande. Elle vous contactera pour vérifier le dossier et confirmer la location.')" />
    <x-section-card :title="__('Récapitulatif')"><p>{{ App\Support\Ui\UiLabel::dateTime($booking->starts_at) }} → {{ App\Support\Ui\UiLabel::dateTime($booking->ends_at) }}</p><p class="mt-3 font-semibold">{{ App\Support\Ui\UiLabel::money($booking->quote_snapshot['total_amount'], $booking->quote_snapshot['currency']) }}</p><p class="mt-3 text-sm text-slate-600">{{ __('Le véhicule reste disponible tant que l’agence n’a pas confirmé la réservation.') }}</p></x-section-card>
    <a class="rf-button-secondary" href="{{ route('booking.catalog', $profile->slug) }}">{{ __('Retour au catalogue') }}</a>
</x-booking-layout>
