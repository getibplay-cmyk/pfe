<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div><a href="{{ route('maintenance.index') }}" class="text-sm text-indigo-700">← Maintenance</a><h1 class="mt-2 text-3xl font-bold">Planifier une maintenance</h1></div>
        <form method="POST" action="{{ route('maintenance.store') }}" class="grid gap-5 rounded-xl bg-white p-6 shadow-sm md:grid-cols-2">
            @csrf
            <label class="text-sm">Agence
                <select name="agency_id" required class="mt-1 w-full rounded-lg border-slate-300"><option value="">Choisir</option>@foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected(old('agency_id') == $agency->id)>{{ $agency->name }}</option>@endforeach</select>
                <x-input-error :messages="$errors->get('agency_id')" class="mt-1" />
            </label>
            <label class="text-sm">Véhicule
                <select name="vehicle_id" required class="mt-1 w-full rounded-lg border-slate-300"><option value="">Choisir</option>@foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}" @selected(old('vehicle_id') == $vehicle->id)>{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }}</option>@endforeach</select>
                <x-input-error :messages="$errors->get('vehicle_id')" class="mt-1" />
            </label>
            <label class="text-sm">Type<select name="maintenance_type" class="mt-1 w-full rounded-lg border-slate-300">@foreach(['preventive'=>'Préventive','corrective'=>'Corrective','inspection'=>'Inspection','repair'=>'Réparation'] as $value => $label)<option value="{{ $value }}" @selected(old('maintenance_type') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm">Priorité<select name="priority" class="mt-1 w-full rounded-lg border-slate-300">@foreach(['low'=>'Basse','normal'=>'Normale','high'=>'Haute','critical'=>'Critique'] as $value => $label)<option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm md:col-span-2">Objet<input name="title" required value="{{ old('title') }}" class="mt-1 w-full rounded-lg border-slate-300"><x-input-error :messages="$errors->get('title')" class="mt-1" /></label>
            <label class="text-sm md:col-span-2">Description<textarea name="description" rows="3" class="mt-1 w-full rounded-lg border-slate-300">{{ old('description') }}</textarea></label>
            <label class="text-sm">Début planifié<input type="datetime-local" name="scheduled_start_at" value="{{ old('scheduled_start_at') }}" class="mt-1 w-full rounded-lg border-slate-300"><x-input-error :messages="$errors->get('scheduled_start_at')" class="mt-1" /></label>
            <label class="text-sm">Fin planifiée<input type="datetime-local" name="scheduled_end_at" value="{{ old('scheduled_end_at') }}" class="mt-1 w-full rounded-lg border-slate-300"><x-input-error :messages="$errors->get('scheduled_end_at')" class="mt-1" /></label>
            <label class="text-sm">Coût estimé (MAD)<input name="estimated_cost" inputmode="decimal" value="{{ old('estimated_cost', '0.00') }}" class="mt-1 w-full rounded-lg border-slate-300"><x-input-error :messages="$errors->get('estimated_cost')" class="mt-1" /></label>
            <label class="text-sm">Prestataire<input name="supplier" value="{{ old('supplier') }}" class="mt-1 w-full rounded-lg border-slate-300"></label>
            <div class="md:col-span-2"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Créer l’ordre</button></div>
        </form>
    </div>
</x-app-layout>
