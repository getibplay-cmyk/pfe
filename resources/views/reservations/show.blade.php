<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header :title="$reservation->reservation_number" :eyebrow="__('Réservation')" description="{{ __('Période affichée dans le fuseau ') }}{{ config('reservations.display_timezone') }}." :breadcrumbs="[['label' => __('Réservations'), 'url' => route('reservations.index')], ['label' => $reservation->reservation_number]]">
            <x-slot:actions>
                <x-status-badge :value="$reservation->status" />
                <a href="{{ route('reservations.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />{{ __('Retour aux réservations') }}</a>
                @can('update', $reservation)<a href="{{ route('reservations.edit', $reservation) }}" class="rf-button-secondary">{{ __('Modifier') }}</a>@endcan
                @if($reservation->status->value === 'confirmed' && !$reservation->rentalContract && auth()->user()->hasPermission('reservation.cancel') && auth()->user()->hasPermission('reservation.create') && auth()->user()->hasPermission('reservation.confirm'))<a class="rf-button-secondary" href="{{ route('fleet.planning.edit', $reservation) }}">{{ __('Déplacer avec aperçu') }}</a>@endif
                @can('confirm', $reservation)<form method="POST" action="{{ route('reservations.confirm', $reservation) }}">@csrf<x-confirmation-button variant="secondary" :message="__('Confirmer cette réservation et bloquer le véhicule sur la période ?')">{{ __('Confirmer et bloquer') }}</x-confirmation-button></form>@endcan
            </x-slot:actions>
        </x-page-header>
        <x-form-errors />
        @foreach($replans as $replan)
            <x-flash-message type="info"><span>{{ $replan->previous_reservation_id === $reservation->id ? __('Cette réservation a été remplacée après validation d’un déplacement.') : __('Cette réservation provient d’un déplacement confirmé.') }} <a class="font-semibold underline" href="{{ route('reservations.show', $replan->previous_reservation_id === $reservation->id ? $replan->replacement_reservation_id : $replan->previous_reservation_id) }}">{{ __('Consulter le dossier lié') }}</a> — {{ $replan->reason }}</span></x-flash-message>
        @endforeach
        @if($reservation->rentalContract)
            <x-flash-message type="info"><span>{{ __('Cette réservation a été convertie.') }} <a href="{{ route('contracts.show', $reservation->rentalContract) }}" class="font-semibold underline">{{ __('Ouvrir le contrat') }} {{ $reservation->rentalContract->contract_number }}</a>.</span></x-flash-message>
        @elseif($reservation->status->value === 'confirmed')
            @can('create', App\Models\RentalContract::class)<form method="POST" action="{{ route('contracts.store', $reservation) }}">@csrf<x-confirmation-button variant="secondary" :message="__('Créer un contrat à partir de cette réservation confirmée ?')">{{ __('Créer le contrat depuis cette réservation') }}</x-confirmation-button></form>@endcan
        @endif
        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card :title="__('Détails de la réservation')">
                <x-metadata-list>
                    <x-metadata-item :label="__('Agence')">{{ $reservation->agency->name }}</x-metadata-item>
                    <x-metadata-item :label="__('Client')">{{ $reservation->customer->displayName() }}</x-metadata-item>
                    <x-metadata-item :label="__('Conducteur')">{{ $reservation->driver ? $reservation->driver->first_name.' '.$reservation->driver->last_name : __('Non sélectionné') }}</x-metadata-item>
                    <x-metadata-item :label="__('Catégorie')">{{ $reservation->vehicleCategory->name }}</x-metadata-item>
                    <x-metadata-item :label="__('Véhicule')">{{ $reservation->vehicle?->registration_number ?? __('Non affecté') }}</x-metadata-item>
                    <x-metadata-item :label="__('Début')">{{ App\Support\Ui\UiLabel::dateTime($reservation->starts_at) }}</x-metadata-item>
                    <x-metadata-item :label="__('Fin')">{{ App\Support\Ui\UiLabel::dateTime($reservation->ends_at) }}</x-metadata-item>
                </x-metadata-list>
            </x-section-card>
            <x-section-card :title="__('Résumé tarifaire')" :description="__('La caution reste séparée du montant de location.')">
                @if($reservation->status->canBeConfirmed() && ! $quote)<x-flash-message type="warning" :message="$quoteError" />@else
                    @php($pricing = $reservation->status->canBeConfirmed() ? $quote : ['billed_days' => $reservation->billed_days, 'daily_rate' => $reservation->daily_rate, 'total_amount' => $reservation->total_amount, 'deposit_amount' => $reservation->deposit_amount, 'currency' => $reservation->currency])
                    <x-metadata-list>
                        <x-metadata-item :label="__('Jours facturés')">{{ $pricing['billed_days'] }}</x-metadata-item>
                        <x-metadata-item :label="__('Tarif journalier')">{{ App\Support\Ui\UiLabel::money($pricing['daily_rate'], $pricing['currency']) }}</x-metadata-item>
                        <x-metadata-item :label="__('Total location')">{{ App\Support\Ui\UiLabel::money($pricing['total_amount'], $pricing['currency']) }}</x-metadata-item>
                        <x-metadata-item :label="__('Caution séparée')">{{ App\Support\Ui\UiLabel::money($pricing['deposit_amount'], $pricing['currency']) }}</x-metadata-item>
                    </x-metadata-list>
                    <p class="mt-4 text-xs leading-5 text-slate-500">{{ $reservation->status->canBeConfirmed() ? __('Aperçu calculé : le détail tarifaire sera figé lors de la confirmation.') : __('Tarification figée lors de la confirmation de la réservation.') }}</p>
                @endif
            </x-section-card>
        </div>
        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card :title="__('Historique des statuts')"><x-timeline :label="__('Historique de la réservation')">@foreach($reservation->statusHistories->sortByDesc('created_at') as $history)<x-timeline-item :title="$history->to_status->label()" :meta="App\Support\Ui\UiLabel::dateTime($history->created_at)" :active="$loop->first">{{ $history->reason }}</x-timeline-item>@endforeach</x-timeline></x-section-card>
            <x-section-card :title="__('Disponibilité et annulation')">
                @php($activeBlock = $reservation->vehicleBlocks->firstWhere('status.value', 'active'))
                <x-flash-message :type="$activeBlock ? 'info' : 'warning'" :message="$activeBlock ? __('Un bloc actif protège le véhicule sur la période de cette réservation.') : __('Aucun bloc actif n’est associé à cette réservation.')" />
                @can('cancel', $reservation)
                    <form method="POST" action="{{ route('reservations.cancel', $reservation) }}" class="mt-6 space-y-3">@csrf
                        <div><x-input-label for="cancellation-reason" :value="__('Motif d’annulation')" required /><textarea id="cancellation-reason" name="reason" required rows="3" class="mt-1 w-full">{{ old('reason') }}</textarea><x-field-error :messages="$errors->get('reason')" class="mt-2" /></div>
                        <x-confirmation-button :message="__('Annuler cette réservation et libérer son bloc de disponibilité ?')">{{ __('Annuler la réservation') }}</x-confirmation-button>
                    </form>
                @endcan
            </x-section-card>
        </div>
    </div>
</x-app-layout>
