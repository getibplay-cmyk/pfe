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

        <x-form-errors />

        <form method="POST" action="{{ $reservation->exists ? route('reservations.update', $reservation) : route('reservations.store') }}" class="grid gap-4 rounded-xl bg-white p-6 shadow-sm md:grid-cols-2">
            @csrf
            @if ($reservation->exists) @method('PUT') @endif
            <input type="hidden" name="agency_id" value="{{ $selectedAgencyId }}">

            <div class="rounded-lg bg-slate-50 p-3 text-sm">
                <span class="text-slate-500">Agence</span>
                <p class="font-medium">{{ $agencies->firstWhere('id', $selectedAgencyId)?->name }}</p>
            </div>
            <label for="reservation-category">Catégorie
                <select id="reservation-category" name="vehicle_category_id" required class="mt-1 w-full rounded-lg border-slate-300" @if($errors->has('vehicle_category_id')) aria-invalid="true" @endif aria-describedby="reservation-category-error">
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('vehicle_category_id', $reservation->vehicle_category_id ?? request('vehicle_category_id')) == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <x-field-error id="reservation-category-error" :messages="$errors->get('vehicle_category_id')" class="mt-2" />
            </label>
            <label for="reservation-customer">Client
                <select id="reservation-customer" name="customer_id" required class="mt-1 w-full rounded-lg border-slate-300" @if($errors->has('customer_id')) aria-invalid="true" @endif aria-describedby="reservation-customer-error">
                    <option value="">Sélectionner</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(old('customer_id', $reservation->customer_id) == $customer->id)>{{ $customer->displayName() }}</option>
                    @endforeach
                </select>
                <x-field-error id="reservation-customer-error" :messages="$errors->get('customer_id')" class="mt-2" />
            </label>
            <label for="reservation-driver">Conducteur
                <select id="reservation-driver" name="driver_id" class="mt-1 w-full rounded-lg border-slate-300" @if($errors->has('driver_id')) aria-invalid="true" @endif aria-describedby="reservation-driver-error">
                    <option value="">À sélectionner avant confirmation</option>
                    @foreach ($customers as $customer)
                        @foreach ($customer->drivers as $driver)
                            <option value="{{ $driver->id }}" @selected(old('driver_id', $reservation->driver_id) == $driver->id)>{{ $driver->first_name }} {{ $driver->last_name }} — {{ $customer->displayName() }}</option>
                        @endforeach
                    @endforeach
                </select>
                <x-field-error id="reservation-driver-error" :messages="$errors->get('driver_id')" class="mt-2" />
            </label>
            <label for="reservation-start">Début
                <input id="reservation-start" type="datetime-local" name="starts_at" value="{{ old('starts_at', $reservation->starts_at?->timezone(config('reservations.display_timezone'))->format('Y-m-d\TH:i') ?? request('starts_at')) }}" required class="mt-1 w-full rounded-lg border-slate-300" @if($errors->has('starts_at')) aria-invalid="true" @endif aria-describedby="reservation-start-error">
                <x-field-error id="reservation-start-error" :messages="$errors->get('starts_at')" class="mt-2" />
            </label>
            <label for="reservation-end">Fin
                <input id="reservation-end" type="datetime-local" name="ends_at" value="{{ old('ends_at', $reservation->ends_at?->timezone(config('reservations.display_timezone'))->format('Y-m-d\TH:i') ?? request('ends_at')) }}" required class="mt-1 w-full rounded-lg border-slate-300" @if($errors->has('ends_at')) aria-invalid="true" @endif aria-describedby="reservation-end-error">
                <x-field-error id="reservation-end-error" :messages="$errors->get('ends_at')" class="mt-2" />
            </label>
            <label for="reservation-vehicle">Véhicule
                <select id="reservation-vehicle" name="vehicle_id" class="mt-1 w-full rounded-lg border-slate-300" @if($errors->has('vehicle_id')) aria-invalid="true" @endif aria-describedby="reservation-vehicle-error">
                    <option value="">À affecter</option>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}" @selected(old('vehicle_id', $reservation->vehicle_id ?? request('vehicle_id')) == $vehicle->id)>{{ $vehicle->registration_number }} — {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                    @endforeach
                </select>
                <x-field-error id="reservation-vehicle-error" :messages="$errors->get('vehicle_id')" class="mt-2" />
            </label>
            <label for="reservation-status">État initial
                <select id="reservation-status" name="status" class="mt-1 w-full rounded-lg border-slate-300" @if($errors->has('status')) aria-invalid="true" @endif aria-describedby="reservation-status-error">
                    <option value="draft" @selected(old('status', $reservation->status?->value ?? 'draft') === 'draft')>Brouillon</option>
                    <option value="pending" @selected(old('status', $reservation->status?->value) === 'pending')>En attente</option>
                </select>
                <x-field-error id="reservation-status-error" :messages="$errors->get('status')" class="mt-2" />
            </label>
            <label for="reservation-expiry">Expiration de l’attente
                <input id="reservation-expiry" type="datetime-local" name="expires_at" value="{{ old('expires_at', $reservation->expires_at?->timezone(config('reservations.display_timezone'))->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-lg border-slate-300" @if($errors->has('expires_at')) aria-invalid="true" @endif aria-describedby="reservation-expiry-error">
                <x-field-error id="reservation-expiry-error" :messages="$errors->get('expires_at')" class="mt-2" />
            </label>
            <label for="reservation-notes" class="md:col-span-2">Notes
                <textarea id="reservation-notes" name="notes" rows="3" class="mt-1 w-full rounded-lg border-slate-300" @if($errors->has('notes')) aria-invalid="true" @endif aria-describedby="reservation-notes-error">{{ old('notes', $reservation->notes) }}</textarea>
                <x-field-error id="reservation-notes-error" :messages="$errors->get('notes')" class="mt-2" />
            </label>
            <p class="md:col-span-2 text-sm text-slate-500">Le tarif est résolu et affiché avant confirmation. Seule la confirmation crée un bloc ferme.</p>
            <div class="md:col-span-2 flex justify-end gap-3">
                <a href="{{ route('reservations.index') }}" class="rounded-lg border px-4 py-2">Annuler</a>
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Enregistrer</button>
            </div>
        </form>
    </div>
</x-app-layout>
