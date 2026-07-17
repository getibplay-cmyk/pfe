<x-app-layout>
    <form class="mx-auto max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm" method="POST" action="{{ route('drivers.update', $driver) }}">
        @csrf @method('PUT')
        <h1 class="text-2xl font-bold">Modifier le conducteur</h1>
        <p class="text-sm text-slate-600">Le statut de vérification se modifie uniquement depuis la fiche conducteur.</p>
        <x-form-errors />
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm">Prénom *<input name="first_name" value="{{ old('first_name', $driver->first_name) }}" required class="mt-1 w-full"><x-input-error :messages="$errors->get('first_name')" /></label>
            <label class="text-sm">Nom *<input name="last_name" value="{{ old('last_name', $driver->last_name) }}" required class="mt-1 w-full"><x-input-error :messages="$errors->get('last_name')" /></label>
            <label class="text-sm">Naissance<input type="date" name="birth_date" value="{{ old('birth_date', $driver->birth_date?->format('Y-m-d')) }}" class="mt-1 w-full"></label>
            <label class="text-sm">Catégorie<input name="licence_category" value="{{ old('licence_category', $driver->licence_category) }}" class="mt-1 w-full"></label>
            <label class="text-sm">Nouveau numéro de permis <span class="text-slate-500">(facultatif)</span><input name="licence_number" value="" class="mt-1 w-full"><x-input-error :messages="$errors->get('licence_number')" /></label>
            <label class="text-sm">Délivré le<input type="date" name="licence_issued_at" value="{{ old('licence_issued_at', $driver->licence_issued_at?->format('Y-m-d')) }}" class="mt-1 w-full"><x-input-error :messages="$errors->get('licence_issued_at')" /></label>
            <label class="text-sm">Expiration *<input type="date" name="licence_expires_at" value="{{ old('licence_expires_at', $driver->licence_expires_at?->format('Y-m-d')) }}" required class="mt-1 w-full"><x-input-error :messages="$errors->get('licence_expires_at')" /></label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary', $driver->is_primary))> Conducteur principal</label>
        </div>
        <button class="rounded-lg bg-slate-950 px-4 py-2 text-white">Enregistrer</button>
    </form>
</x-app-layout>
