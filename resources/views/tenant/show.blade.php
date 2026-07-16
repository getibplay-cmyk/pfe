<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <x-page-header title="Paramètres de l’entreprise" eyebrow="Administration tenant" description="Le slug et l’état de service sont gérés uniquement par l’administration plateforme." />
        @can('update', $tenant)
            <form method="POST" action="{{ route('tenant.update') }}" class="space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">@csrf @method('PATCH')
                <x-form-errors />
                <div class="grid gap-5 md:grid-cols-2">
                    <label class="text-sm">Nom commercial *<input name="name" required value="{{ old('name', $tenant->name) }}" class="mt-1 w-full"><x-input-error :messages="$errors->get('name')" /></label>
                    <label class="text-sm">Raison sociale<input name="legal_name" value="{{ old('legal_name', $tenant->legal_name) }}" class="mt-1 w-full"><x-input-error :messages="$errors->get('legal_name')" /></label>
                    <label class="text-sm">E-mail<input type="email" name="email" value="{{ old('email', $tenant->email) }}" class="mt-1 w-full"><x-input-error :messages="$errors->get('email')" /></label>
                    <label class="text-sm">Téléphone<input name="phone" value="{{ old('phone', $tenant->phone) }}" class="mt-1 w-full"><x-input-error :messages="$errors->get('phone')" /></label>
                    <label class="text-sm md:col-span-2">Adresse<textarea name="address" class="mt-1 w-full">{{ old('address', $tenant->settings['address'] ?? '') }}</textarea><x-input-error :messages="$errors->get('address')" /></label>
                    <label class="text-sm">Devise par défaut *<input name="currency" required maxlength="3" value="{{ old('currency', $tenant->settings['currency'] ?? 'MAD') }}" class="mt-1 w-full"><x-input-error :messages="$errors->get('currency')" /></label>
                    <label class="text-sm">Fuseau horaire *<input name="timezone" required value="{{ old('timezone', $tenant->settings['timezone'] ?? 'Africa/Casablanca') }}" class="mt-1 w-full"><x-input-error :messages="$errors->get('timezone')" /></label>
                </div>
                <button type="submit" class="rounded-lg bg-slate-950 px-5 py-2 text-white">Enregistrer</button>
            </form>
        @else
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="font-semibold">Consultation uniquement</h2><dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2"><div><dt class="text-slate-500">Nom commercial</dt><dd>{{ $tenant->name }}</dd></div><div><dt class="text-slate-500">Raison sociale</dt><dd>{{ $tenant->legal_name ?? '—' }}</dd></div><div><dt class="text-slate-500">Devise</dt><dd>{{ $tenant->settings['currency'] ?? 'MAD' }}</dd></div><div><dt class="text-slate-500">Fuseau horaire</dt><dd>{{ $tenant->settings['timezone'] ?? 'Africa/Casablanca' }}</dd></div></dl></section>
        @endcan
    </div>
</x-app-layout>
