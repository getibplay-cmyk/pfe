<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6">
        <x-page-header title="Nouveau bloc manuel" eyebrow="Flotte" description="La période sera protégée par la contrainte anti-chevauchement PostgreSQL." />
        <x-form-errors />

        <form method="POST" action="{{ route('vehicle-blocks.store') }}" class="grid gap-4 rounded-xl bg-white p-6 shadow-sm sm:grid-cols-2">
            @csrf
            <label class="text-sm">Agence *
                <select name="agency_id" required class="mt-1 w-full">
                    <option value="">Choisir</option>
                    @foreach($agencies as $agency)
                        <option value="{{ $agency->id }}" @selected(old('agency_id', $agencies->count() === 1 ? $agencies->first()->id : null) == $agency->id)>{{ $agency->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('agency_id')" />
            </label>
            <label class="text-sm">Véhicule actif *
                <select name="vehicle_id" required class="mt-1 w-full">
                    <option value="">Choisir</option>
                    @foreach($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}" @selected(old('vehicle_id', $selectedVehicleId) == $vehicle->id)>{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('vehicle_id')" />
            </label>
            <label class="text-sm">Début *
                <input type="datetime-local" name="starts_at" required value="{{ old('starts_at', now()->addHour()->format('Y-m-d\TH:i')) }}" class="mt-1 w-full">
                <x-input-error :messages="$errors->get('starts_at')" />
            </label>
            <label class="text-sm">Fin *
                <input type="datetime-local" name="ends_at" required value="{{ old('ends_at', now()->addDay()->format('Y-m-d\TH:i')) }}" class="mt-1 w-full">
                <x-input-error :messages="$errors->get('ends_at')" />
            </label>
            <label class="text-sm sm:col-span-2">Motif *
                <textarea name="reason" required maxlength="2000" rows="4" class="mt-1 w-full">{{ old('reason') }}</textarea>
                <x-input-error :messages="$errors->get('reason')" />
            </label>
            <div class="flex gap-3 sm:col-span-2">
                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Créer le bloc</button>
                <a href="{{ route('vehicle-blocks.index') }}" class="rounded-lg border px-4 py-2">Annuler</a>
            </div>
        </form>
    </div>
</x-app-layout>
