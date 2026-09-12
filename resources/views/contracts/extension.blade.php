<x-app-layout><div class="mx-auto max-w-4xl space-y-6">
    <x-page-header :title="__('Prolongation').' — '.$contract->contract_number" :description="$extension->label()"><x-slot:actions><a class="rf-button-secondary" href="{{ route('contract-extensions.index') }}">{{ __('Retour aux demandes') }}</a></x-slot:actions></x-page-header>
    <x-form-errors />
    <x-section-card :title="__('Demande du locataire')"><x-metadata-list>
        <x-metadata-item :label="__('Véhicule')">{{ $contract->vehicle->registration_number }}</x-metadata-item>
        <x-metadata-item :label="__('Retour actuel')">{{ App\Support\Ui\UiLabel::dateTime($contract->expected_return_at) }}</x-metadata-item>
        <x-metadata-item :label="__('Retour demandé')">{{ App\Support\Ui\UiLabel::dateTime($extension->requested_return_at) }}</x-metadata-item>
        <x-metadata-item :label="__('Message')">{{ $extension->customer_note ?: '—' }}</x-metadata-item>
    </x-metadata-list></x-section-card>
    @if($extension->status === 'requested')
        <x-section-card :title="__('Préparer une proposition')" :description="__('Préparez un PDF d’avenant avec la date demandée, le supplément, les kilomètres inclus et les conditions. Le locataire le lira avant d’accepter.')">
            <form method="POST" enctype="multipart/form-data" action="{{ route('contract-extensions.offer', $extension) }}" class="space-y-4">@csrf
                <div class="grid gap-4 sm:grid-cols-2"><div><x-input-label for="extension-amount" :value="__('Supplément de location').' ('.$contract->currency.')'" required /><input id="extension-amount" name="additional_amount" inputmode="decimal" pattern="[0-9]{1,9}([.][0-9]{1,2})?" required value="{{ old('additional_amount') }}" class="mt-1 w-full"><x-field-error :messages="$errors->get('additional_amount')" /></div><div><x-input-label for="extension-km" :value="__('Kilomètres supplémentaires inclus')" required /><input id="extension-km" name="included_km" type="number" min="0" max="1000000" required value="{{ old('included_km', 0) }}" class="mt-1 w-full"></div></div>
                <div><x-input-label for="extension-pdf" :value="__('PDF de l’avenant (5 Mo maximum)')" required /><input id="extension-pdf" name="document" type="file" accept="application/pdf,.pdf" required class="mt-1 w-full"><x-field-error :messages="$errors->get('document')" /></div>
                <div><x-input-label for="agency-note" :value="__('Message au locataire')" /><textarea id="agency-note" name="agency_note" rows="3" maxlength="1000" class="mt-1 w-full">{{ old('agency_note') }}</textarea></div>
                <label class="flex items-start gap-3"><input type="checkbox" name="document_matches" value="1" required class="mt-1"><span>{{ __('Je confirme que le PDF correspond aux dates, au supplément et aux kilomètres inclus affichés.') }}</span></label>
                <x-primary-button>{{ __('Proposer au locataire') }}</x-primary-button>
            </form>
        </x-section-card>
    @elseif($extension->additional_amount !== null)
        <x-section-card :title="__('Proposition enregistrée')"><p>{{ __('Supplément de location') }} : {{ App\Support\Ui\UiLabel::money($extension->additional_amount, $extension->currency) }}</p><p class="mt-2">{{ $extension->agency_note }}</p><p class="mt-2">{{ $extension->resolution_note }}</p></x-section-card>
    @endif
    @if(in_array($extension->status, ['requested', 'offered']))
        <x-section-card :title="__('Refuser la demande')"><form method="POST" action="{{ route('contract-extensions.reject', $extension) }}" class="space-y-3">@csrf<div><x-input-label for="rejection" :value="__('Motif communiqué au locataire')" required /><textarea id="rejection" name="agency_note" required minlength="5" maxlength="1000" rows="2" class="mt-1 w-full"></textarea></div><x-secondary-button type="submit">{{ __('Refuser la demande') }}</x-secondary-button></form></x-section-card>
    @endif
</div></x-app-layout>
