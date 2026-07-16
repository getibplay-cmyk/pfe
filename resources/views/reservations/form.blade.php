<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <p class="text-sm text-slate-500">{{ $reservation->exists ? $reservation->reservation_number : 'Nouveau brouillon' }}</p>
            <h1 class="text-2xl font-bold">Réservation</h1>
        </div>

        @if ($agencies->count() > 1)
            <form method="GET" action="{{ $reservation->exists ? route('reservations.edit', $reservation) : route('reservations.create') }}" class="rounded-xl border border-slate-200 bg-white p-4">
                <label class="text-sm font-medium">Agence de travail
                    <select name="agency_id" onchange="this.form.submit()" class="mt-1 w-full rounded-lg border-slate-300 md:w-80">
                        @foreach ($agencies as $agency)
                            <option value="{{ $agency->id }}" @selected($selectedAgencyId === $agency->id)>{{ $agency->name }}</option>
                        @endforeach
                    </select>
                </label>
                <noscript><button class="ml-2 rounded-lg border px-3 py-2">Actualiser les ressources</button></noscript>
            </form>
        @endif

        @if ($errors->any())
            <div class="rounded-lg bg-red-50 p-4 text-sm text-red-800">
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ $reservation->exists ? route('reservations.update', $reservation) : route('reservations.store') }}" class="grid gap-4 rounded-xl bg-white p-6 shadow-sm md:grid-cols-2">
            @csrf
            @if ($reservation->exists) @method('PUT') @endif
            <input type="hidden" name="agency_id" value="{{ $selectedAgencyId }}">

            <div class="rounded-lg bg-slate-50 p-3 text-sm">
                <span class="text-slate-500">Agence</span>
                <p class="font-medium">{{ $agencies->firstWhere('id', $selectedAgencyId)?->name }}</p>
            </div>
            <label>Catégorie
                <select name="vehicle_category_id" required class="mt-1 w-full rounded-lg border-slate-300">
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('vehicle_category_id', $reservation->vehicle_category_id ?? request('vehicle_category_id')) == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Client
                <select name="customer_id" required class="mt-1 w-full rounded-lg border-slate-300">
                    <option value="">Sélectionner</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(old('customer_id', $reservation->customer_id) == $customer->id)>{{ $customer->displayName() }}</option>
                    @endforeach
                </select>
            </label>
            <label>Conducteur
                <select name="driver_id" class="mt-1 w-full rounded-lg border-slate-300">
                    <option value="">À sélectionner avant confirmation</option>
                    @foreach ($customers as $customer)
                        @foreach ($customer->drivers as $driver)
                            <option value="{{ $driver->id }}" @selected(old('driver_id', $reservation->driver_id) == $driver->id)>{{ $driver->first_name }} {{ $driver->last_name }} — {{ $customer->displayName() }}</option>
                        @endforeach
                    @endforeach
                </select>
            </label>
            <label>Début
                <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $reservation->starts_at?->timezone(config('reservations.display_timezone'))->format('Y-m-d\TH:i') ?? request('starts_at')) }}" required class="mt-1 w-full rounded-lg border-slate-300">
            </label>
            <label>Fin
                <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $reservation->ends_at?->timezone(config('reservations.display_timezone'))->format('Y-m-d\TH:i') ?? request('ends_at')) }}" required class="mt-1 w-full rounded-lg border-slate-300">
            </label>
            <label>Véhicule
                <select name="vehicle_id" class="mt-1 w-full rounded-lg border-slate-300">
                    <option value="">À affecter</option>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}" @selected(old('vehicle_id', $reservation->vehicle_id ?? request('vehicle_id')) == $vehicle->id)>{{ $vehicle->registration_number }} — {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                    @endforeach
                </select>
            </label>
            <label>État initial
                <select name="status" class="mt-1 w-full rounded-lg border-slate-300">
                    <option value="draft" @selected(old('status', $reservation->status?->value ?? 'draft') === 'draft')>Brouillon</option>
                    <option value="pending" @selected(old('status', $reservation->status?->value) === 'pending')>En attente</option>
                </select>
            </label>
            <label>Expiration de l’attente
                <input type="datetime-local" name="expires_at" value="{{ old('expires_at', $reservation->expires_at?->timezone(config('reservations.display_timezone'))->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-lg border-slate-300">
            </label>
            <label class="md:col-span-2">Notes
                <textarea name="notes" rows="3" class="mt-1 w-full rounded-lg border-slate-300">{{ old('notes', $reservation->notes) }}</textarea>
            </label>
            <p class="md:col-span-2 text-sm text-slate-500">Le tarif est résolu et affiché avant confirmation. Seule la confirmation crée un bloc ferme.</p>
            <div class="md:col-span-2 flex justify-end gap-3">
                <a href="{{ route('reservations.index') }}" class="rounded-lg border px-4 py-2">Annuler</a>
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Enregistrer</button>
            </div>
        </form>
    </div>
</x-app-layout>
