<x-app-layout>
    <form class="mx-auto max-w-4xl space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm" method="POST" action="{{ $vehicle->exists ? route('vehicles.update', $vehicle) : route('vehicles.store') }}">
        @csrf @if($vehicle->exists) @method('PUT') @endif
        <h1 class="text-2xl font-bold">{{ $vehicle->exists ? 'Modifier le véhicule' : 'Nouveau véhicule' }}</h1>
        <x-form-errors />
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm">Agence *<select class="mt-1 w-full" name="agency_id">@foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected(old('agency_id', $vehicle->agency_id) == $agency->id)>{{ $agency->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('agency_id')" /></label>
            <label class="text-sm">Catégorie *<select class="mt-1 w-full" name="vehicle_category_id">@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('vehicle_category_id', $vehicle->vehicle_category_id) == $category->id)>{{ $category->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('vehicle_category_id')" /></label>
            @foreach(['registration_number'=>'Immatriculation *','vin'=>'VIN','brand'=>'Marque *','model'=>'Modèle *','production_year'=>'Année','color'=>'Couleur','current_mileage'=>'Kilométrage *'] as $name => $label)
                <label class="text-sm">{{ $label }}<input class="mt-1 w-full" name="{{ $name }}" value="{{ old($name, $vehicle->$name) }}"><x-input-error :messages="$errors->get($name)" /></label>
            @endforeach
            <label class="text-sm">Carburant *<select class="mt-1 w-full" name="fuel_type">@foreach(['petrol','diesel','hybrid','electric','other'] as $value)<option value="{{ $value }}" @selected(old('fuel_type', $vehicle->fuel_type) === $value)>{{ App\Support\Ui\UiLabel::get($value) }}</option>@endforeach</select></label>
            <label class="text-sm">Transmission *<select class="mt-1 w-full" name="transmission">@foreach(['manual','automatic'] as $value)<option value="{{ $value }}" @selected(old('transmission', $vehicle->transmission) === $value)>{{ App\Support\Ui\UiLabel::get($value) }}</option>@endforeach</select></label>
        </div>
        <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-white">Enregistrer</button>
    </form>
</x-app-layout>
