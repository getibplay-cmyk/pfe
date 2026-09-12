<x-booking-layout :profile="$profile" :title="$listing->vehicle->brand.' '.$listing->vehicle->model">
    <a class="rf-button-secondary" href="{{ route('booking.catalog', $profile->slug) }}">{{ __('Retour au catalogue') }}</a>
    <x-section-card :title="__('Vos dates de location')">
        <form method="GET" class="grid gap-4 sm:grid-cols-2"><div><x-input-label for="booking-start" :value="__('Départ')" required /><input type="datetime-local" id="booking-start" name="starts_at" required value="{{ request('starts_at') }}" class="mt-1 w-full"></div><div><x-input-label for="booking-end" :value="__('Retour')" required /><input type="datetime-local" id="booking-end" name="ends_at" required value="{{ request('ends_at') }}" class="mt-1 w-full"></div><p class="text-sm text-slate-500">{{ __('Heures locales') }} : {{ config('app.timezone') }}</p><div><x-primary-button>{{ __('Calculer mon devis') }}</x-primary-button></div></form>
    </x-section-card>
    @if($quote)
        <x-section-card :title="__('Votre devis')"><x-metadata-list><x-metadata-item :label="__('Jours facturés')">{{ $quote['price']['billed_days'] }}</x-metadata-item><x-metadata-item :label="__('Total location')">{{ App\Support\Ui\UiLabel::money($quote['price']['total_amount'], $quote['price']['currency']) }}</x-metadata-item><x-metadata-item :label="__('Caution séparée')">{{ App\Support\Ui\UiLabel::money($quote['price']['deposit_amount'], $quote['price']['currency']) }}</x-metadata-item></x-metadata-list><p class="mt-4 text-sm text-slate-600">{{ __('Devis valable 20 minutes pour l’envoi de la demande. L’agence vous confirmera les conditions et la réservation après vérification de votre dossier.') }}</p></x-section-card>
        @if(!$quote['available'])<x-flash-message type="warning" :message="__('Ce véhicule est indisponible sur cette période. Essayez d’autres dates ou un autre véhicule.')" />@else
            <x-section-card :title="__('Envoyer une demande à l’agence')">
                <form method="POST" action="{{ route('booking.store', [$profile->slug, $listing->id]) }}" class="space-y-4">@csrf<input type="hidden" name="proposal" value="{{ $quote['proposal'] }}">
                    <div class="hidden" aria-hidden="true"><label for="booking-website">{{ __('Website') }}</label><input id="booking-website" name="website" tabindex="-1" autocomplete="off"></div>
                    <div class="grid gap-4 sm:grid-cols-2"><div><x-input-label for="booking-first" :value="__('Prénom')" required /><input id="booking-first" name="first_name" autocomplete="given-name" required maxlength="100" value="{{ old('first_name') }}" class="mt-1 w-full"></div><div><x-input-label for="booking-last" :value="__('Nom')" required /><input id="booking-last" name="last_name" autocomplete="family-name" required maxlength="100" value="{{ old('last_name') }}" class="mt-1 w-full"></div><div><x-input-label for="booking-email" :value="__('Adresse e-mail')" /><input id="booking-email" name="email" type="email" autocomplete="email" maxlength="254" value="{{ old('email') }}" class="mt-1 w-full"></div><div><x-input-label for="booking-phone" :value="__('Téléphone')" /><input id="booking-phone" name="phone" type="tel" autocomplete="tel" maxlength="30" value="{{ old('phone') }}" class="mt-1 w-full"></div></div>
                    <p class="text-sm text-slate-500">{{ __('Indiquez au moins une adresse e-mail ou un numéro de téléphone.') }}</p>
                    <div><x-input-label for="booking-message" :value="__('Message à l’agence')" /><textarea id="booking-message" name="message" rows="3" maxlength="1000" class="mt-1 w-full">{{ old('message') }}</textarea></div>
                    <label class="flex items-start gap-3"><input type="checkbox" name="consent" value="1" required class="mt-1"><span>{{ __('J’autorise cette agence à utiliser mes coordonnées pour traiter ma demande. Je comprends que cette demande ne bloque pas encore le véhicule.') }}</span></label>
                    <x-primary-button>{{ __('Envoyer ma demande') }}</x-primary-button>
                </form>
            </x-section-card>
        @endif
    @endif
</x-booking-layout>
