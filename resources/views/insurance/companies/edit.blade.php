<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6"><div><a href="{{ route('insurance.companies.show', $company) }}" class="text-sm text-indigo-700">← Compagnie</a><h1 class="mt-2 text-3xl font-bold">Modifier la compagnie</h1></div><x-form-errors />
        <form method="POST" action="{{ route('insurance.companies.update', $company) }}" class="grid gap-5 rounded-xl bg-white p-6 shadow-sm md:grid-cols-2">@csrf @method('PUT')
            <label class="text-sm md:col-span-2">Nom *<input name="name" required value="{{ old('name', $company->name) }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('name')" /></label>
            <label class="text-sm">E-mail<input name="email" type="email" value="{{ old('email', $company->email) }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('email')" /></label>
            <label class="text-sm">Téléphone<input name="phone" value="{{ old('phone', $company->phone) }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('phone')" /></label>
            <div class="md:col-span-2"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Enregistrer</button></div>
        </form>
    </div>
</x-app-layout>
