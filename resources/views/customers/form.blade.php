<x-app-layout>
    <form class="mx-auto max-w-4xl space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm" method="POST" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">
        @csrf
        @if ($customer->exists) @method('PUT') @endif
        <h1 class="text-2xl font-bold">{{ $customer->exists ? 'Modifier le client' : 'Nouveau client' }}</h1>
        <x-form-errors />

        @if ($customer->exists)
            <p class="text-sm text-slate-600">Statut de vérification : <x-status-badge :value="$customer->verification_status" /></p>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm">Type *
                <select name="customer_type" required class="mt-1 w-full">
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('customer_type', $customer->customer_type?->value) === $type->value)>{{ App\Support\Ui\UiLabel::get($type) }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('customer_type')" />
            </label>
            <label class="text-sm">Agence *
                <select name="agency_id" required class="mt-1 w-full">
                    <option value="" disabled @selected(! old('agency_id', $customer->agency_id))>Choisir une agence</option>
                    @foreach ($agencies as $agency)
                        <option value="{{ $agency->id }}" @selected(old('agency_id', $customer->agency_id) == $agency->id)>{{ $agency->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('agency_id')" />
            </label>
            @foreach (['first_name' => 'Prénom', 'last_name' => 'Nom', 'company_name' => 'Société', 'email' => 'E-mail', 'phone' => 'Téléphone', 'city' => 'Ville', 'nationality' => 'Nationalité', 'birth_date' => 'Naissance', 'identity_type' => 'Type d’identité'] as $name => $label)
                <label class="text-sm">{{ $label }}
                    <input class="mt-1 w-full" type="{{ $name === 'birth_date' ? 'date' : ($name === 'email' ? 'email' : 'text') }}" name="{{ $name }}" value="{{ old($name, $customer->$name) }}">
                    <x-input-error :messages="$errors->get($name)" />
                </label>
            @endforeach
            <label class="text-sm">Numéro d’identité {{ $customer->exists ? '(laisser vide pour conserver)' : '' }}
                <input class="mt-1 w-full" type="text" name="identity_number" value="">
                <x-input-error :messages="$errors->get('identity_number')" />
            </label>
            <label class="text-sm sm:col-span-2">Adresse
                <textarea class="mt-1 w-full" name="address" rows="2">{{ old('address', $customer->address) }}</textarea>
                <x-input-error :messages="$errors->get('address')" />
            </label>
            <label class="text-sm sm:col-span-2">Notes
                <textarea class="mt-1 w-full" name="notes" rows="3">{{ old('notes', $customer->notes) }}</textarea>
                <x-input-error :messages="$errors->get('notes')" />
            </label>
        </div>
        <p class="text-xs text-slate-500">Le statut est géré uniquement par les actions Vérifier ou Rejeter. Les numéros d’identité restent chiffrés et masqués.</p>
        <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-white">Enregistrer</button>
    </form>
</x-app-layout>
