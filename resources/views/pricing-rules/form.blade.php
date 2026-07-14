<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6"><div><p class="text-sm text-slate-500">{{ $pricingRule->exists ? 'Versionner sans écraser l’historique' : 'Nouvelle règle' }}</p><h1 class="text-2xl font-bold">Règle tarifaire</h1></div>
        @if($errors->any())<div class="rounded-lg bg-red-50 p-4 text-sm text-red-800"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ $pricingRule->exists ? route('pricing-rules.update', $pricingRule) : route('pricing-rules.store') }}" class="grid gap-4 rounded-xl bg-white p-6 shadow-sm md:grid-cols-2">@csrf @if($pricingRule->exists)@method('PUT')@endif
            <label class="md:col-span-2">Nom<input name="name" value="{{ old('name', $pricingRule->name) }}" required class="mt-1 w-full rounded-lg border-slate-300"></label>
            <label>Agence<select name="agency_id" class="mt-1 w-full rounded-lg border-slate-300">@if(auth()->user()->agency_id === null)<option value="">Règle générale tenant</option>@endif @foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected(old('agency_id', $pricingRule->agency_id) == $agency->id)>{{ $agency->name }}</option>@endforeach</select></label>
            <label>Catégorie<select name="vehicle_category_id" required class="mt-1 w-full rounded-lg border-slate-300">@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('vehicle_category_id', $pricingRule->vehicle_category_id) == $category->id)>{{ $category->name }}</option>@endforeach</select></label>
            <label>Tarif journalier MAD<input type="number" step="0.01" min="0" name="daily_rate" value="{{ old('daily_rate', $pricingRule->daily_rate ?? '0.00') }}" required class="mt-1 w-full rounded-lg border-slate-300"></label>
            <label>Caution MAD<input type="number" step="0.01" min="0" name="deposit_amount" value="{{ old('deposit_amount', $pricingRule->deposit_amount ?? '0.00') }}" required class="mt-1 w-full rounded-lg border-slate-300"></label>
            <label>Km inclus/jour<input type="number" min="0" name="included_km_per_day" value="{{ old('included_km_per_day', $pricingRule->included_km_per_day) }}" class="mt-1 w-full rounded-lg border-slate-300"></label>
            <label>Prix km supplémentaire<input type="number" step="0.01" min="0" name="extra_km_rate" value="{{ old('extra_km_rate', $pricingRule->extra_km_rate) }}" class="mt-1 w-full rounded-lg border-slate-300"></label>
            <label>Prix heure de retard<input type="number" step="0.01" min="0" name="late_hour_rate" value="{{ old('late_hour_rate', $pricingRule->late_hour_rate) }}" class="mt-1 w-full rounded-lg border-slate-300"></label>
            <label>Minimum de jours<input type="number" min="1" name="minimum_days" value="{{ old('minimum_days', $pricingRule->minimum_days ?? 1) }}" required class="mt-1 w-full rounded-lg border-slate-300"></label>
            <label>Maximum de jours<input type="number" min="1" name="maximum_days" value="{{ old('maximum_days', $pricingRule->maximum_days) }}" class="mt-1 w-full rounded-lg border-slate-300"></label>
            <label>Valide à partir du<input type="date" name="valid_from" value="{{ old('valid_from', $pricingRule->valid_from?->format('Y-m-d') ?? today()->format('Y-m-d')) }}" required class="mt-1 w-full rounded-lg border-slate-300"></label>
            <label>Valide jusqu’au<input type="date" name="valid_to" value="{{ old('valid_to', $pricingRule->valid_to?->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border-slate-300"></label>
            <label>Priorité<input type="number" name="priority" value="{{ old('priority', $pricingRule->priority ?? 0) }}" required class="mt-1 w-full rounded-lg border-slate-300"></label>
            <input type="hidden" name="currency" value="MAD"><input type="hidden" name="is_active" value="1">
            <div class="md:col-span-2 flex justify-end gap-3"><a href="{{ route('pricing-rules.index') }}" class="rounded-lg border px-4 py-2">Annuler</a><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">{{ $pricingRule->exists ? 'Créer la nouvelle version' : 'Créer' }}</button></div>
        </form>
    </div>
</x-app-layout>
