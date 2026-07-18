<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><a href="{{ route('maintenance.index') }}" class="text-sm text-indigo-700">← Maintenance</a><h1 class="mt-2 text-3xl font-bold">{{ $maintenance->maintenance_number }}</h1><p class="text-slate-600">{{ $maintenance->title }}</p></div>
            <div class="flex flex-wrap items-center gap-2"><x-status-badge :value="$maintenance->status" />@can('update', $maintenance)<a href="{{ route('maintenance.edit', $maintenance) }}" class="rounded-lg border px-3 py-2 text-sm">Modifier</a>@endcan @can('reschedule', $maintenance)<a href="{{ route('maintenance.reschedule.edit', $maintenance) }}" class="rounded-lg border px-3 py-2 text-sm">Replanifier</a>@endcan</div>
        </div>
        @if($errors->any())<div class="rounded-lg bg-red-50 p-4 text-sm text-red-800"><p class="font-semibold">La demande n’a pas pu être traitée.</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl bg-white p-5 shadow-sm lg:col-span-2">
                <h2 class="font-semibold">Détails de l’intervention</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-slate-500">Véhicule</dt><dd><a class="underline" href="{{ route('vehicles.show', $maintenance->vehicle) }}">{{ $maintenance->vehicle->registration_number }}</a></dd></div>
                    <div><dt class="text-slate-500">Agence</dt><dd>{{ $maintenance->agency->name }}</dd></div>
                    <div><dt class="text-slate-500">Type / priorité</dt><dd>{{ App\Support\Ui\UiLabel::get($maintenance->maintenance_type) }} · {{ App\Support\Ui\UiLabel::get($maintenance->priority) }}</dd></div>
                    <div><dt class="text-slate-500">Période planifiée</dt><dd>{{ App\Support\Ui\UiLabel::dateTime($maintenance->scheduled_start_at) }} → {{ App\Support\Ui\UiLabel::dateTime($maintenance->scheduled_end_at) }}</dd></div>
                    <div><dt class="text-slate-500">Période réelle</dt><dd>{{ App\Support\Ui\UiLabel::dateTime($maintenance->actual_start_at) }} → {{ App\Support\Ui\UiLabel::dateTime($maintenance->actual_end_at) }}</dd></div>
                    <div><dt class="text-slate-500">Prestataire</dt><dd>{{ $maintenance->supplier ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Kilométrage ouverture / actuel</dt><dd>{{ $maintenance->mileage_at_opening ?? '—' }} / {{ $maintenance->vehicle->current_mileage }}</dd></div>
                    <div><dt class="text-slate-500">Coût estimé / réel</dt><dd>{{ App\Support\Ui\UiLabel::money($maintenance->estimated_cost, 'MAD') }} / {{ App\Support\Ui\UiLabel::money($maintenance->actual_cost, 'MAD') }}</dd></div>
                    <div><dt class="text-slate-500">Prochaine échéance</dt><dd>{{ App\Support\Ui\UiLabel::date($maintenance->next_due_date) }} · {{ $maintenance->next_due_mileage ? $maintenance->next_due_mileage.' km' : '—' }}</dd></div>
                </dl>
                @if($maintenance->description)<p class="mt-5 whitespace-pre-line border-t pt-4 text-sm text-slate-700">{{ $maintenance->description }}</p>@endif
            </section>
            <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">Bloc véhicule</h2>@if($maintenance->vehicleBlock)<p class="mt-3 text-sm"><x-status-badge :value="$maintenance->vehicleBlock->status" /></p><p class="mt-2 text-sm">{{ App\Support\Ui\UiLabel::dateTime($maintenance->vehicleBlock->starts_at) }} → {{ App\Support\Ui\UiLabel::dateTime($maintenance->vehicleBlock->ends_at) }}</p>@else<x-empty-state title="Aucun bloc" description="Le bloc est créé lors de l’approbation." />@endif</section>
        </div>

        <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">Actions autorisées</h2><div class="mt-4 space-y-4">
            @can('approve', $maintenance)<form method="POST" action="{{ route('maintenance.approve', $maintenance) }}" onsubmit="return confirm('Approuver et bloquer le véhicule ?')">@csrf<button class="rounded-lg bg-indigo-700 px-4 py-2 text-white">Approuver</button></form>@endcan
            @can('start', $maintenance)<form method="POST" action="{{ route('maintenance.start', $maintenance) }}" onsubmit="return confirm('Démarrer cette maintenance ?')">@csrf<button class="rounded-lg bg-indigo-700 px-4 py-2 text-white">Démarrer</button></form>@endcan
            @can('complete', $maintenance)
                <form method="POST" action="{{ route('maintenance.complete', $maintenance) }}" class="grid gap-3 md:grid-cols-3" onsubmit="return confirm('Terminer définitivement cette maintenance ?')">@csrf
                    <label class="text-sm">Coût réel (MAD)<input name="actual_cost" inputmode="decimal" required value="{{ old('actual_cost', $maintenance->actual_cost) }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('actual_cost')" /></label>
                    <label class="text-sm">Kilométrage final<input type="number" name="mileage" required min="{{ max($maintenance->mileage_at_opening ?? 0, $maintenance->vehicle->current_mileage) }}" value="{{ old('mileage', $maintenance->vehicle->current_mileage) }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('mileage')" /></label>
                    <label class="text-sm">Prochaine date<input type="date" name="next_due_date" value="{{ old('next_due_date') }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('next_due_date')" /></label>
                    <label class="text-sm">Prochain kilométrage<input type="number" name="next_due_mileage" value="{{ old('next_due_mileage') }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('next_due_mileage')" /></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="return_to_active" value="1" @checked(old('return_to_active'))> Confirmer humainement le retour à l’état actif</label>
                    <label class="text-sm md:col-span-2">Note<input name="reason" value="{{ old('reason') }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('reason')" /></label>
                    <div class="md:col-span-3"><button class="rounded-lg bg-emerald-700 px-4 py-2 text-white">Terminer</button></div>
                </form>
            @endcan
            @can('cancel', $maintenance)<form method="POST" action="{{ route('maintenance.cancel', $maintenance) }}" class="flex flex-wrap gap-2" onsubmit="return confirm('Annuler cet ordre et libérer uniquement son bloc ?')">@csrf<input name="reason" required value="{{ old('reason') }}" placeholder="Motif obligatoire" class="rounded border-slate-300"><button class="rounded-lg border border-red-300 px-4 py-2 text-red-700">Annuler</button></form>@endcan
        </div></section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">Timeline immuable</h2><ol class="mt-4 space-y-3 text-sm">@forelse($maintenance->histories->sortByDesc(fn ($history) => [$history->created_at, $history->id]) as $history)<li class="border-l-2 border-slate-300 pl-3">{{ $history->from_status ? App\Support\Ui\UiLabel::get($history->from_status) : 'Création' }} → {{ App\Support\Ui\UiLabel::get($history->to_status) }} <span class="text-slate-500">{{ App\Support\Ui\UiLabel::dateTime($history->created_at) }}</span>@if($history->reason)<p class="text-slate-500">{{ $history->reason }}</p>@endif</li>@empty<x-empty-state title="Aucun historique" />@endforelse</ol></section>
            <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">Dépense générée</h2>@forelse($maintenance->expenses as $expense)<p class="mt-3 text-sm" data-amount="{{ $expense->amount }}"><a class="underline" href="{{ route('finance.index') }}">{{ $expense->expense_number }}</a> · {{ App\Support\Ui\UiLabel::money($expense->amount, $expense->currency) }} · {{ App\Support\Ui\UiLabel::get($expense->status) }}</p>@empty<x-empty-state title="Aucune dépense" description="Une dépense brouillon unique est créée si le coût réel est supérieur à zéro." />@endforelse</section>
        </div>

        <section class="rounded-xl bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold">Documents privés</h2><p class="text-sm text-slate-500">Les factures fournisseur jointes sont des justificatifs et ne deviennent pas des factures comptables officielles.</p></div></div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">@forelse($maintenance->documents as $document)@can('view', $document)<a href="{{ route('documents.show', $document) }}" class="rounded-lg border p-3 text-sm"><strong>{{ $document->title }}</strong><p class="text-slate-500">{{ App\Support\Ui\UiLabel::get($document->document_type) }} · version {{ $document->currentVersion?->version_number ?? '—' }}</p></a>@endcan @empty<x-empty-state title="Aucun document" description="Ajoutez un devis, un ordre, une facture fournisseur ou un rapport d’intervention." />@endforelse</div>
            @can('uploadDocument', $maintenance)
                <form method="POST" enctype="multipart/form-data" action="{{ route('maintenance.documents.store', $maintenance) }}" class="mt-5 grid gap-3 border-t pt-5 sm:grid-cols-2">@csrf<input type="hidden" name="is_sensitive" value="1">
                    <label class="text-sm">Type<select name="document_type" required class="mt-1 w-full rounded border-slate-300">@foreach($documentTypes as $type)<option value="{{ $type->value }}">{{ App\Support\Ui\UiLabel::get($type) }}</option>@endforeach</select><x-input-error :messages="$errors->get('document_type')" /></label>
                    <label class="text-sm">Titre<input name="title" required class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('title')" /></label>
                    <label class="text-sm">Fichier privé<input type="file" name="file" required class="mt-1 block w-full"><x-input-error :messages="$errors->get('file')" /></label>
                    <label class="text-sm">Conservation jusqu’au<input type="date" name="retention_until" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('retention_until')" /></label>
                    <div class="sm:col-span-2"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Ajouter le document privé</button></div>
                </form>
            @endcan
        </section>
    </div>
</x-app-layout>
