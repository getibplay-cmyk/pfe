<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div><a href="{{ route('insurance.index') }}" class="text-sm text-indigo-700">← Assurance</a><h1 class="mt-2 text-3xl font-bold">Créer une police</h1></div>
        <form method="POST" action="{{ route('insurance.policies.store') }}" class="grid gap-5 rounded-xl bg-white p-6 shadow-sm md:grid-cols-2">@csrf
            <label class="text-sm">Agence<select name="agency_id" required class="mt-1 w-full rounded border-slate-300"><option value="">Choisir</option>@foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected(old('agency_id') == $agency->id)>{{ $agency->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('agency_id')" /></label>
            <label class="text-sm">Véhicule<select name="vehicle_id" required class="mt-1 w-full rounded border-slate-300"><option value="">Choisir</option>@foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}" @selected(old('vehicle_id') == $vehicle->id)>{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }}</option>@endforeach</select><x-input-error :messages="$errors->get('vehicle_id')" /></label>
            <label class="text-sm">Compagnie<select name="insurance_company_id" required class="mt-1 w-full rounded border-slate-300"><option value="">Choisir</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('insurance_company_id') == $company->id)>{{ $company->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('insurance_company_id')" /></label>
            <label class="text-sm">Numéro de police<input name="policy_number" required value="{{ old('policy_number') }}" autocomplete="off" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('policy_number')" /></label>
            <label class="text-sm">Type<select name="policy_type" class="mt-1 w-full rounded border-slate-300">@foreach(['mandatory_liability'=>'Responsabilité obligatoire','comprehensive'=>'Tous risques','third_party'=>'Tiers','other'=>'Autre'] as $value => $label)<option value="{{ $value }}" @selected(old('policy_type') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm">Statut<select name="status" class="mt-1 w-full rounded border-slate-300">@foreach(['draft'=>'Brouillon','active'=>'Active','expired'=>'Expirée','cancelled'=>'Annulée'] as $value => $label)<option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm">Début<input type="date" name="starts_at" required value="{{ old('starts_at') }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm">Fin<input type="date" name="ends_at" required value="{{ old('ends_at') }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('ends_at')" /></label>
            <label class="text-sm">Prime (MAD)<input name="premium_amount" inputmode="decimal" required value="{{ old('premium_amount', '0.00') }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('premium_amount')" /></label>
            <label class="text-sm">Franchise (MAD)<input name="deductible_amount" inputmode="decimal" required value="{{ old('deductible_amount', '0.00') }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('deductible_amount')" /></label>
            <input type="hidden" name="currency" value="MAD">
            <div class="md:col-span-2"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Enregistrer la police</button></div>
        </form>
    </div>
</x-app-layout>
