<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><a href="{{ route('maintenance.index') }}" class="text-sm text-indigo-700">← Maintenance</a><h1 class="mt-2 text-3xl font-bold">{{ $maintenance->maintenance_number }}</h1><p class="text-slate-600">{{ $maintenance->title }}</p></div>
            <span class="rounded-full bg-slate-200 px-3 py-1 text-sm font-semibold">{{ str_replace('_', ' ', $maintenance->status) }}</span>
        </div>
        @if($errors->any())<div class="rounded-lg bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl bg-white p-5 shadow-sm lg:col-span-2">
                <h2 class="font-semibold">Détails</h2>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-slate-500">Véhicule</dt><dd><a class="underline" href="{{ route('vehicles.show', $maintenance->vehicle) }}">{{ $maintenance->vehicle->registration_number }}</a></dd></div><div><dt class="text-slate-500">Type</dt><dd>{{ $maintenance->maintenance_type }}</dd></div><div><dt class="text-slate-500">Période</dt><dd>{{ $maintenance->scheduled_start_at?->format('d/m/Y H:i') ?? '—' }} → {{ $maintenance->scheduled_end_at?->format('d/m/Y H:i') ?? '—' }}</dd></div><div><dt class="text-slate-500">Coûts</dt><dd>{{ $maintenance->estimated_cost }} / {{ $maintenance->actual_cost }} MAD</dd></div></dl>
            </section>
            <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">Bloc véhicule</h2>@if($maintenance->vehicleBlock)<p class="mt-3 text-sm">{{ $maintenance->vehicleBlock->status->value }} · {{ $maintenance->vehicleBlock->starts_at->format('d/m/Y H:i') }} → {{ $maintenance->vehicleBlock->ends_at->format('d/m/Y H:i') }}</p>@else<p class="mt-3 text-sm text-slate-500">Aucun bloc avant approbation.</p>@endif</section>
        </div>

        <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">Actions autorisées</h2><div class="mt-4 space-y-4">
            @if($maintenance->status === 'planned' && auth()->user()->hasPermission('maintenance.approve'))<form method="POST" action="{{ route('maintenance.approve', $maintenance) }}" onsubmit="return confirm('Approuver et bloquer le véhicule ?')">@csrf<button class="rounded-lg bg-indigo-700 px-4 py-2 text-white">Approuver</button></form>@endif
            @if($maintenance->status === 'approved' && auth()->user()->hasPermission('maintenance.start'))<form method="POST" action="{{ route('maintenance.start', $maintenance) }}" onsubmit="return confirm('Démarrer cette maintenance ?')">@csrf<button class="rounded-lg bg-indigo-700 px-4 py-2 text-white">Démarrer</button></form>@endif
            @if($maintenance->status === 'in_progress' && auth()->user()->hasPermission('maintenance.complete'))
                <form method="POST" action="{{ route('maintenance.complete', $maintenance) }}" class="grid gap-3 md:grid-cols-3" onsubmit="return confirm('Terminer définitivement cette maintenance ?')">@csrf
                    <label class="text-sm">Coût réel (MAD)<input name="actual_cost" inputmode="decimal" required value="{{ old('actual_cost', $maintenance->actual_cost) }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('actual_cost')" /></label>
                    <label class="text-sm">Kilométrage<input type="number" name="mileage" required min="{{ $maintenance->vehicle->current_mileage }}" value="{{ old('mileage', $maintenance->vehicle->current_mileage) }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('mileage')" /></label>
                    <label class="text-sm">Prochaine date<input type="date" name="next_due_date" value="{{ old('next_due_date') }}" class="mt-1 w-full rounded border-slate-300"></label>
                    <label class="text-sm">Prochain kilométrage<input type="number" name="next_due_mileage" value="{{ old('next_due_mileage') }}" class="mt-1 w-full rounded border-slate-300"></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="return_to_active" value="1" @checked(old('return_to_active'))> Confirmer le retour du véhicule à l’état actif</label>
                    <label class="text-sm md:col-span-2">Note<input name="reason" value="{{ old('reason') }}" class="mt-1 w-full rounded border-slate-300"></label>
                    <div class="md:col-span-3"><button class="rounded-lg bg-emerald-700 px-4 py-2 text-white">Terminer</button></div>
                </form>
            @endif
            @if(in_array($maintenance->status, ['planned','approved']) && auth()->user()->hasPermission('maintenance.cancel'))<form method="POST" action="{{ route('maintenance.cancel', $maintenance) }}" class="flex flex-wrap gap-2" onsubmit="return confirm('Annuler cet ordre ?')">@csrf<input name="reason" required value="{{ old('reason') }}" placeholder="Motif obligatoire" class="rounded border-slate-300"><button class="rounded-lg border border-red-300 px-4 py-2 text-red-700">Annuler</button></form>@endif
        </div></section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">Historique</h2><ol class="mt-4 space-y-2 text-sm">@forelse($maintenance->histories->sortByDesc('created_at') as $history)<li class="border-l-2 border-slate-300 pl-3">{{ $history->from_status ?? 'Création' }} → {{ $history->to_status }} <span class="text-slate-500">{{ $history->created_at->format('d/m/Y H:i') }}</span>@if($history->reason)<p class="text-slate-500">{{ $history->reason }}</p>@endif</li>@empty<li class="text-slate-500">Aucun historique.</li>@endforelse</ol></section>
            <section class="rounded-xl bg-white p-5 shadow-sm"><h2 class="font-semibold">Dépense générée</h2>@forelse($maintenance->expenses as $expense)<p class="mt-3 text-sm"><a class="underline" href="{{ route('finance.index') }}">{{ $expense->expense_number }}</a> · {{ $expense->amount }} {{ $expense->currency }} · {{ $expense->status }}</p>@empty<p class="mt-3 text-sm text-slate-500">Aucune dépense tant que la maintenance n’est pas terminée avec un coût réel.</p>@endforelse</section>
        </div>
    </div>
</x-app-layout>
