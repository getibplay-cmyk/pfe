<x-portal-layout :title="__('Mon contrat').' '.$contract->contract_number">
    <a class="rf-button-secondary" href="{{ route('portal.home') }}">{{ __('Retour à mon espace') }}</a>
    <x-section-card :title="$contract->vehicle->brand.' '.$contract->vehicle->model">
        <x-status-badge :value="$contract->status" />
        <x-metadata-list class="mt-4">
            <x-metadata-item :label="__('Retour prévu')">{{ App\Support\Ui\UiLabel::dateTime($contract->expected_return_at) }}</x-metadata-item>
            <x-metadata-item :label="__('Montant de location')">{{ App\Support\Ui\UiLabel::money($contract->rental_subtotal, $contract->currency) }}</x-metadata-item>
        </x-metadata-list>
        @if($contract->currentVersion?->document_id)<a class="rf-button-link mt-4 inline-flex" data-no-global-loading="true" href="{{ route('portal.contract.version', [$contract->id, $contract->current_version_id]) }}">{{ __('Lire le document du contrat') }}</a>@endif
        @if($proposal)
            <form method="POST" action="{{ route('portal.contract.accept', $contract->id) }}" class="mt-5 space-y-4">@csrf
                <input type="hidden" name="proposal" value="{{ $proposal }}">
                <div><x-input-label for="accepted-name" :value="__('Votre nom complet')" required /><input id="accepted-name" name="accepted_by_name" required minlength="3" maxlength="200" autocomplete="name" value="{{ old('accepted_by_name') }}" class="mt-1 w-full"><x-field-error :messages="$errors->get('accepted_by_name')" /></div>
                <label class="flex items-start gap-3"><input type="checkbox" name="consent" value="1" required class="mt-1"><span>{{ __('J’ai lu le document et j’accepte cette version du contrat en mon nom.') }}</span></label>
                <x-primary-button>{{ __('Accepter le contrat') }}</x-primary-button>
            </form>
        @endif
    </x-section-card>
    <x-section-card :title="__('Mes demandes de prolongation')" :description="__('Une demande seule ne prolonge pas la location. L’agence prépare une proposition que vous devez accepter.')">
        <div class="space-y-4">@forelse($extensions as $extension)
            <article class="rounded-xl border border-slate-200 p-4">
                <div class="flex flex-wrap justify-between gap-3"><h2 class="font-semibold">{{ __('Retour demandé') }} : {{ App\Support\Ui\UiLabel::dateTime($extension->requested_return_at) }}</h2><span class="text-sm font-semibold">{{ $extension->label() }}</span></div>
                @if($extension->customer_note)<p class="mt-2 text-sm">{{ $extension->customer_note }}</p>@endif
                @if($extension->agency_note)<p class="mt-2 text-sm">{{ __('Réponse de l’agence') }} : {{ $extension->agency_note }}</p>@endif
                @if($extension->resolution_note)<p class="mt-2 text-sm">{{ $extension->resolution_note }}</p>@endif
                @if($extension->additional_amount !== null)<p class="mt-3 font-semibold">{{ __('Supplément de location') }} : {{ App\Support\Ui\UiLabel::money($extension->additional_amount, $extension->currency) }}</p><p class="mt-1 text-sm">{{ __('Kilomètres supplémentaires inclus') }} : {{ data_get($extension->offer_snapshot, 'terms_snapshot.extension.included_km', 0) }}</p>@endif
                @if(in_array($extension->status, ['offered', 'accepted']))<a class="rf-button-link mt-3 inline-flex" data-no-global-loading="true" href="{{ route('portal.extension.document', [$contract->id, $extension->id]) }}">{{ __('Lire l’avenant') }}</a>@endif
                @if($extension->status === 'offered' && $extension->expires_at->isFuture())
                    <p class="mt-2 text-sm text-slate-600">{{ __('Proposition valable jusqu’au') }} {{ App\Support\Ui\UiLabel::dateTime($extension->expires_at) }}.</p>
                    <form method="POST" action="{{ route('portal.extension.accept', [$contract->id, $extension->id]) }}" class="mt-4 space-y-3">@csrf
                        <div><x-input-label :for="'extension-name-'.$extension->id" :value="__('Votre nom complet')" required /><input id="extension-name-{{ $extension->id }}" name="accepted_by_name" required minlength="3" maxlength="200" autocomplete="name" class="mt-1 w-full"></div>
                        <label class="flex items-start gap-3"><input type="checkbox" name="consent" value="1" required class="mt-1"><span>{{ __('J’ai lu l’avenant et j’accepte le nouveau retour et le supplément affiché.') }}</span></label>
                        <x-primary-button>{{ __('Accepter la prolongation') }}</x-primary-button>
                    </form>
                @endif
                @if(in_array($extension->status, ['requested', 'offered']))<form method="POST" action="{{ route('portal.extension.withdraw', [$contract->id, $extension->id]) }}" class="mt-3">@csrf<x-secondary-button type="submit">{{ __('Retirer cette demande') }}</x-secondary-button></form>@endif
            </article>
        @empty<x-empty-state :title="__('Aucune demande de prolongation')" />@endforelse</div>
        <div class="mt-4">{{ $extensions->links() }}</div>
    </x-section-card>
    @if(in_array($contract->status->value, ['accepted', 'active']))
        <x-section-card :title="__('Demander une prolongation')">
            <form method="POST" action="{{ route('portal.extension.request', $contract->id) }}" class="space-y-4">@csrf
                <div><x-input-label for="new-return" :value="__('Date et heure de retour souhaitées')" required /><input id="new-return" name="requested_return_at" type="datetime-local" required min="{{ $contract->expected_return_at->addMinute()->format('Y-m-d\TH:i') }}" max="{{ $contract->expected_return_at->addDays(30)->format('Y-m-d\TH:i') }}" class="mt-1 w-full"><p class="mt-1 text-sm text-slate-500">{{ config('app.timezone') }}</p><x-field-error :messages="$errors->get('requested_return_at')" /></div>
                <div><x-input-label for="extension-note" :value="__('Message à l’agence')" /><textarea id="extension-note" name="customer_note" rows="3" maxlength="1000" class="mt-1 w-full">{{ old('customer_note') }}</textarea></div>
                <x-primary-button>{{ __('Envoyer la demande') }}</x-primary-button>
            </form>
        </x-section-card>
    @endif
</x-portal-layout>
