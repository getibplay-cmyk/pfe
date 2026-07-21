<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header :title="$reservation->reservation_number" eyebrow="Réservation" description="Période affichée dans le fuseau {{ config('reservations.display_timezone') }}.">
            <x-slot:actions>
                <x-status-badge :value="$reservation->status" />
                @can('update', $reservation)<a href="{{ route('reservations.edit', $reservation) }}" class="rf-button-secondary">Modifier</a>@endcan
                @can('confirm', $reservation)<form method="POST" action="{{ route('reservations.confirm', $reservation) }}">@csrf<x-confirmation-button variant="secondary" message="Confirmer cette réservation et bloquer le véhicule sur la période ?">Confirmer et bloquer</x-confirmation-button></form>@endcan
            </x-slot:actions>
        </x-page-header>
        <x-form-errors />
        @if($reservation->rentalContract)
            <x-flash-message type="info"><span>Cette réservation a été convertie. <a href="{{ route('contracts.show', $reservation->rentalContract) }}" class="font-semibold underline">Ouvrir le contrat {{ $reservation->rentalContract->contract_number }}</a>.</span></x-flash-message>
        @elseif($reservation->status->value === 'confirmed')
            @can('create', App\Models\RentalContract::class)<form method="POST" action="{{ route('contracts.store', $reservation) }}">@csrf<x-confirmation-button variant="secondary" message="Créer un contrat à partir de cette réservation confirmée ?">Créer le contrat depuis cette réservation</x-confirmation-button></form>@endcan
        @endif
        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Détails de la réservation">
                <x-metadata-list>
                    <x-metadata-item label="Agence">{{ $reservation->agency->name }}</x-metadata-item>
                    <x-metadata-item label="Client">{{ $reservation->customer->displayName() }}</x-metadata-item>
                    <x-metadata-item label="Conducteur">{{ $reservation->driver ? $reservation->driver->first_name.' '.$reservation->driver->last_name : 'Non sélectionné' }}</x-metadata-item>
                    <x-metadata-item label="Catégorie">{{ $reservation->vehicleCategory->name }}</x-metadata-item>
                    <x-metadata-item label="Véhicule">{{ $reservation->vehicle?->registration_number ?? 'Non affecté' }}</x-metadata-item>
                    <x-metadata-item label="Début">{{ App\Support\Ui\UiLabel::dateTime($reservation->starts_at) }}</x-metadata-item>
                    <x-metadata-item label="Fin">{{ App\Support\Ui\UiLabel::dateTime($reservation->ends_at) }}</x-metadata-item>
                </x-metadata-list>
            </x-section-card>
            <x-section-card title="Résumé tarifaire" description="La caution reste séparée du montant de location.">
                @if($reservation->status->canBeConfirmed() && ! $quote)<x-flash-message type="warning" :message="$quoteError" />@else
                    @php($pricing = $reservation->status->canBeConfirmed() ? $quote : ['billed_days' => $reservation->billed_days, 'daily_rate' => $reservation->daily_rate, 'total_amount' => $reservation->total_amount, 'deposit_amount' => $reservation->deposit_amount, 'currency' => $reservation->currency])
                    <x-metadata-list>
                        <x-metadata-item label="Jours facturés">{{ $pricing['billed_days'] }}</x-metadata-item>
                        <x-metadata-item label="Tarif journalier">{{ App\Support\Ui\UiLabel::money($pricing['daily_rate'], $pricing['currency']) }}</x-metadata-item>
                        <x-metadata-item label="Total location">{{ App\Support\Ui\UiLabel::money($pricing['total_amount'], $pricing['currency']) }}</x-metadata-item>
                        <x-metadata-item label="Caution séparée">{{ App\Support\Ui\UiLabel::money($pricing['deposit_amount'], $pricing['currency']) }}</x-metadata-item>
                    </x-metadata-list>
                    <p class="mt-4 text-xs leading-5 text-slate-500">{{ $reservation->status->canBeConfirmed() ? 'Aperçu calculé : le détail tarifaire sera figé lors de la confirmation.' : 'Tarification figée lors de la confirmation de la réservation.' }}</p>
                @endif
            </x-section-card>
        </div>
        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Historique des statuts"><x-timeline label="Historique de la réservation">@foreach($reservation->statusHistories->sortByDesc('created_at') as $history)<x-timeline-item :title="$history->to_status->label()" :meta="App\Support\Ui\UiLabel::dateTime($history->created_at)" :active="$loop->first">{{ $history->reason }}</x-timeline-item>@endforeach</x-timeline></x-section-card>
            <x-section-card title="Disponibilité et annulation">
                @php($activeBlock = $reservation->vehicleBlocks->firstWhere('status.value', 'active'))
                <x-flash-message :type="$activeBlock ? 'info' : 'warning'" :message="$activeBlock ? 'Un bloc actif protège le véhicule sur la période de cette réservation.' : 'Aucun bloc actif n’est associé à cette réservation.'" />
                @can('cancel', $reservation)
                    <form method="POST" action="{{ route('reservations.cancel', $reservation) }}" class="mt-6 space-y-3">@csrf
                        <div><x-input-label for="cancellation-reason" value="Motif d’annulation" required /><textarea id="cancellation-reason" name="reason" required rows="3" class="mt-1 w-full">{{ old('reason') }}</textarea><x-field-error :messages="$errors->get('reason')" class="mt-2" /></div>
                        <x-confirmation-button message="Annuler cette réservation et libérer son bloc de disponibilité ?">Annuler la réservation</x-confirmation-button>
                    </form>
                @endcan
            </x-section-card>
        </div>
    </div>
</x-app-layout>
