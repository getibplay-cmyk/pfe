<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><a href="{{ route('maintenance.index') }}" class="text-sm text-indigo-700">{{ __('← Maintenance') }}</a><h1 class="mt-2 text-3xl font-bold">{{ $maintenance->maintenance_number }}</h1><p class="text-slate-600">{{ $maintenance->title }}</p></div>
            <div class="flex flex-wrap items-center gap-2"><x-status-badge :value="$maintenance->status" />@can('update', $maintenance)<a href="{{ route('maintenance.edit', $maintenance) }}" class="rounded-lg border px-3 py-2 text-sm">{{ __('Modifier') }}</a>@endcan @can('reschedule', $maintenance)<a href="{{ route('maintenance.reschedule.edit', $maintenance) }}" class="rounded-lg border px-3 py-2 text-sm">{{ __('Replanifier') }}</a>@endcan</div>
        </div>
        @if($errors->any())<div class="rounded-lg bg-red-50 p-4 text-sm text-red-800"><p class="font-semibold">{{ __('La demande n’a pas pu être traitée.') }}</p><ul class="mt-2 list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl bg-white p-5 shadow-sm lg:col-span-2">
                <h2 class="font-semibold">{{ __('Détails de l’intervention') }}</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-slate-500">{{ __('Véhicule') }}</dt><dd><a class="underline" href="{{ route('vehicles.show', $maintenance->vehicle) }}">{{ $maintenance->vehicle->registration_number }}</a></dd></div>
                    <div><dt class="text-slate-500">{{ __('Agence') }}</dt><dd>{{ $maintenance->agency->name }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Type / priorité') }}</dt><dd>{{ App\Support\Ui\UiLabel::get($maintenance->maintenance_type) }} · {{ App\Support\Ui\UiLabel::get($maintenance->priority) }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Période planifiée') }}</dt><dd>{{ App\Support\Ui\UiLabel::dateTime($maintenance->scheduled_start_at) }} → {{ App\Support\Ui\UiLabel::dateTime($maintenance->scheduled_end_at) }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Période réelle') }}</dt><dd>{{ App\Support\Ui\UiLabel::dateTime($maintenance->actual_start_at) }} → {{ App\Support\Ui\UiLabel::dateTime($maintenance->actual_end_at) }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Prestataire') }}</dt><dd>{{ $maintenance->supplier ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Kilométrage ouverture / actuel') }}</dt><dd>{{ $maintenance->mileage_at_opening ?? '—' }} / {{ $maintenance->vehicle->current_mileage }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Coût estimé / réel') }}</dt><dd>{{ App\Support\Ui\UiLabel::money($maintenance->estimated_cost, 'MAD') }} / {{ App\Support\Ui\UiLabel::money($maintenance->actual_cost, 'MAD') }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Prochaine échéance') }}</dt><dd>{{ App\Support\Ui\UiLabel::date($maintenance->next_due_date) }} · {{ $maintenance->next_due_mileage ? $maintenance->next_due_mileage.' km' : '—' }}</dd></div>
                </dl>
                @if($maintenance->description)<p class="mt-5 whitespace-pre-line border-t pt-4 text-sm text-slate-700">{{ $maintenance->description }}</p>@endif
            </section>
            <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ __('Bloc véhicule') }}</h2>@if($maintenance->vehicleBlock)<p class="mt-3 text-sm"><x-status-badge :value="$maintenance->vehicleBlock->status" /></p><p class="mt-2 text-sm">{{ App\Support\Ui\UiLabel::dateTime($maintenance->vehicleBlock->starts_at) }} → {{ App\Support\Ui\UiLabel::dateTime($maintenance->vehicleBlock->ends_at) }}</p>@else<x-empty-state :title="__('Aucun bloc')" :description="__('Le bloc est créé lors de l’approbation.')" />@endif</section>
        </div>

        <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ __('Actions autorisées') }}</h2><div class="mt-4 space-y-4">
            @can('approve', $maintenance)<form method="POST" action="{{ route('maintenance.approve', $maintenance) }}" x-belkhir-space-confirm data-confirm-title="{{ __('Approuver la maintenance') }}" data-confirm-resource="{{ __('Ordre de maintenance sélectionné') }}" data-confirm-consequence="{{ __('L’ordre sera approuvé et le véhicule sera bloqué selon la période planifiée.') }}" data-confirm-label="{{ __('Approuver') }}">@csrf<button class="rounded-lg bg-indigo-700 px-4 py-2 text-white">{{ __('Approuver') }}</button></form>@endcan
            @can('start', $maintenance)<form method="POST" action="{{ route('maintenance.start', $maintenance) }}" x-belkhir-space-confirm data-confirm-title="{{ __('Démarrer la maintenance') }}" data-confirm-resource="{{ __('Ordre de maintenance sélectionné') }}" data-confirm-consequence="{{ __('La maintenance passera à l’état en cours selon les règles existantes.') }}" data-confirm-label="{{ __('Démarrer') }}">@csrf<button class="rounded-lg bg-indigo-700 px-4 py-2 text-white">{{ __('Démarrer') }}</button></form>@endcan
            @can('complete', $maintenance)
                <form method="POST" action="{{ route('maintenance.complete', $maintenance) }}" class="grid gap-3 md:grid-cols-3" x-belkhir-space-confirm data-confirm-title="{{ __('Terminer la maintenance') }}" data-confirm-resource="{{ __('Ordre de maintenance sélectionné') }}" data-confirm-consequence="{{ __('Les informations finales seront enregistrées et cette transition ne pourra pas être annulée depuis cet écran.') }}" data-confirm-label="{{ __('Terminer') }}">@csrf
                    <label class="text-sm">{{ __('Coût réel (MAD)') }}<input name="actual_cost" inputmode="decimal" required value="{{ old('actual_cost', $maintenance->actual_cost) }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('actual_cost')" /></label>
                    <label class="text-sm">{{ __('Kilométrage final') }}<input type="number" name="mileage" required min="{{ max($maintenance->mileage_at_opening ?? 0, $maintenance->vehicle->current_mileage) }}" value="{{ old('mileage', $maintenance->vehicle->current_mileage) }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('mileage')" /></label>
                    <label class="text-sm">{{ __('Prochaine date') }}<input type="date" name="next_due_date" value="{{ old('next_due_date') }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('next_due_date')" /></label>
                    <label class="text-sm">{{ __('Prochain kilométrage') }}<input type="number" name="next_due_mileage" value="{{ old('next_due_mileage') }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('next_due_mileage')" /></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="return_to_active" value="1" @checked(old('return_to_active'))> {{ __('Confirmer humainement le retour à l’état actif') }}</label>
                    <label class="text-sm md:col-span-2">{{ __('Note') }}<input name="reason" value="{{ old('reason') }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('reason')" /></label>
                    <div class="md:col-span-3"><button class="rounded-lg bg-emerald-700 px-4 py-2 text-white">{{ __('Terminer') }}</button></div>
                </form>
            @endcan
            @can('cancel', $maintenance)<form method="POST" action="{{ route('maintenance.cancel', $maintenance) }}" class="flex flex-wrap gap-2" x-belkhir-space-confirm data-confirm-title="{{ __('Annuler l’ordre de maintenance') }}" data-confirm-resource="{{ __('Ordre de maintenance sélectionné') }}" data-confirm-consequence="{{ __('L’ordre sera annulé et seul son bloc véhicule sera libéré.') }}" data-confirm-label="{{ __('Annuler l’ordre') }}">@csrf<input name="reason" required value="{{ old('reason') }}" placeholder="{{ __('Motif obligatoire') }}" class="rounded border-slate-300"><button class="rounded-lg border border-red-300 px-4 py-2 text-red-700">{{ __('Annuler') }}</button></form>@endcan
        </div></section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ __('Historique de la maintenance') }}</h2><ol class="mt-4 space-y-3 text-sm">@forelse($maintenance->histories->sortByDesc(fn ($history) => [$history->created_at, $history->id]) as $history)<li class="border-l-2 border-slate-300 ps-3">{{ $history->from_status ? App\Support\Ui\UiLabel::get($history->from_status) : __('Création') }} → {{ App\Support\Ui\UiLabel::get($history->to_status) }} <span class="text-slate-500">{{ App\Support\Ui\UiLabel::dateTime($history->created_at) }}</span>@if($history->reason)<p class="text-slate-500">{{ $history->reason }}</p>@endif</li>@empty<x-empty-state :title="__('Aucun historique')" />@endforelse</ol></section>
            <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ __('Dépense générée') }}</h2>@forelse($maintenance->expenses as $expense)<p class="mt-3 text-sm" data-amount="{{ $expense->amount }}"><a class="underline" href="{{ route('finance.index') }}">{{ $expense->expense_number }}</a> · {{ App\Support\Ui\UiLabel::money($expense->amount, $expense->currency) }} · {{ App\Support\Ui\UiLabel::get($expense->status) }}</p>@empty<x-empty-state :title="__('Aucune dépense')" :description="__('Une dépense brouillon unique est créée si le coût réel est supérieur à zéro.')" />@endforelse</section>
        </div>

        <section class="rounded-xl bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold">{{ __('Documents privés') }}</h2><p class="text-sm text-slate-500">{{ __('Les factures fournisseur jointes sont des justificatifs et ne deviennent pas des factures comptables officielles.') }}</p></div></div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">@forelse($maintenance->documents as $document)@can('view', $document)<a href="{{ route('documents.show', $document) }}" class="rounded-lg border p-3 text-sm"><strong>{{ $document->title }}</strong><p class="text-slate-500">{{ App\Support\Ui\UiLabel::get($document->document_type) }} {{ __('· version') }} {{ $document->currentVersion?->version_number ?? '—' }}</p></a>@endcan @empty<x-empty-state :title="__('Aucun document')" :description="__('Ajoutez un devis, un ordre, une facture fournisseur ou un rapport d’intervention.')" />@endforelse</div>
            @can('uploadDocument', $maintenance)
                <form method="POST" enctype="multipart/form-data" action="{{ route('maintenance.documents.store', $maintenance) }}" class="mt-5 grid gap-3 border-t pt-5 sm:grid-cols-2" data-loading-form>@csrf<input type="hidden" name="is_sensitive" value="1">
                    <label class="text-sm">{{ __('Type') }}<select name="document_type" required class="mt-1 w-full rounded border-slate-300">@foreach($documentTypes as $type)<option value="{{ $type->value }}">{{ App\Support\Ui\UiLabel::get($type) }}</option>@endforeach</select><x-input-error :messages="$errors->get('document_type')" /></label>
                    <label class="text-sm">{{ __('Titre') }}<input name="title" required class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('title')" /></label>
                    <x-file-input id="maintenance-document-file" name="file" :label="__('Fichier privé')" required :errors="$errors->get('file')" />
                    <label class="text-sm">{{ __('Conservation jusqu’au') }}<input type="date" name="retention_until" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('retention_until')" /></label>
                    <div class="sm:col-span-2"><x-submit-button :label="__('Ajouter le document privé')" loading-label="{{ __('Ajout en cours…') }}" /></div>
                </form>
            @endcan
        </section>
    </div>
</x-app-layout>
