@php($editing = $maintenance->exists)
@if (! $editing)
    <label class="text-sm">Agence
        <select name="agency_id" required class="mt-1 w-full rounded-lg border-slate-300">
            <option value="">Choisir</option>
            @foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected(old('agency_id') == $agency->id)>{{ $agency->name }}</option>@endforeach
        </select>
        <x-input-error :messages="$errors->get('agency_id')" class="mt-1" />
    </label>
@endif
<label class="text-sm">Véhicule
    <select name="vehicle_id" required class="mt-1 w-full rounded-lg border-slate-300">
        <option value="">Choisir</option>
        @foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}" @selected(old('vehicle_id', $maintenance->vehicle_id) == $vehicle->id)>{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }}</option>@endforeach
    </select>
    <x-input-error :messages="$errors->get('vehicle_id')" class="mt-1" />
</label>
<label class="text-sm">Type
    <select name="maintenance_type" required class="mt-1 w-full rounded-lg border-slate-300">@foreach(['preventive'=>'Préventive','corrective'=>'Corrective','inspection'=>'Contrôle','repair'=>'Réparation'] as $value => $label)<option value="{{ $value }}" @selected(old('maintenance_type', $maintenance->maintenance_type ?: 'preventive') === $value)>{{ $label }}</option>@endforeach</select>
    <x-input-error :messages="$errors->get('maintenance_type')" class="mt-1" />
</label>
<label class="text-sm">Priorité
    <select name="priority" required class="mt-1 w-full rounded-lg border-slate-300">@foreach(['low'=>'Basse','normal'=>'Normale','high'=>'Haute','critical'=>'Critique'] as $value => $label)<option value="{{ $value }}" @selected(old('priority', $maintenance->priority ?: 'normal') === $value)>{{ $label }}</option>@endforeach</select>
    <x-input-error :messages="$errors->get('priority')" class="mt-1" />
</label>
<label class="text-sm md:col-span-2">Objet
    <input name="title" required value="{{ old('title', $maintenance->title) }}" class="mt-1 w-full rounded-lg border-slate-300">
    <x-input-error :messages="$errors->get('title')" class="mt-1" />
</label>
<label class="text-sm md:col-span-2">Description
    <textarea name="description" rows="3" class="mt-1 w-full rounded-lg border-slate-300">{{ old('description', $maintenance->description) }}</textarea>
    <x-input-error :messages="$errors->get('description')" class="mt-1" />
</label>
<label class="text-sm">Début planifié
    <input type="datetime-local" name="scheduled_start_at" required value="{{ old('scheduled_start_at', $maintenance->scheduled_start_at?->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-lg border-slate-300">
    <x-input-error :messages="$errors->get('scheduled_start_at')" class="mt-1" />
</label>
<label class="text-sm">Fin planifiée
    <input type="datetime-local" name="scheduled_end_at" required value="{{ old('scheduled_end_at', $maintenance->scheduled_end_at?->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-lg border-slate-300">
    <x-input-error :messages="$errors->get('scheduled_end_at')" class="mt-1" />
</label>
<label class="text-sm">Kilométrage d’ouverture
    <input type="number" min="0" name="mileage_at_opening" value="{{ old('mileage_at_opening', $maintenance->mileage_at_opening) }}" class="mt-1 w-full rounded-lg border-slate-300">
    <x-input-error :messages="$errors->get('mileage_at_opening')" class="mt-1" />
</label>
<label class="text-sm">Coût estimé (MAD)
    <input name="estimated_cost" inputmode="decimal" required value="{{ old('estimated_cost', $maintenance->estimated_cost ?? '0.00') }}" class="mt-1 w-full rounded-lg border-slate-300">
    <x-input-error :messages="$errors->get('estimated_cost')" class="mt-1" />
</label>
<label class="text-sm md:col-span-2">Prestataire
    <input name="supplier" value="{{ old('supplier', $maintenance->supplier) }}" class="mt-1 w-full rounded-lg border-slate-300">
    <x-input-error :messages="$errors->get('supplier')" class="mt-1" />
</label>
<div class="md:col-span-2"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">{{ $editing ? 'Enregistrer les modifications' : 'Créer l’ordre' }}</button></div>
