<x-app-layout>
    @php
        $initialColor = old('color', $vehicle->color) ?? '';
        $assistantConfiguration = [
            'initialColor' => $initialColor,
            'ready' => $colorAssistantReady,
            'storeUrl' => $colorAssistantEnabled ? route('vehicles.color-assistant.store') : '',
        ];
    @endphp
    <form
        class="mx-auto max-w-4xl space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
        method="POST"
        action="{{ $vehicle->exists ? route('vehicles.update', $vehicle) : route('vehicles.store') }}"
        x-data='vehicleColorAssistant(@json($assistantConfiguration))'
    >
        @csrf
        @if ($vehicle->exists) @method('PUT') @endif
        <h1 class="text-2xl font-bold">{{ $vehicle->exists ? 'Modifier le véhicule' : 'Nouveau véhicule' }}</h1>
        <x-form-errors />
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm">
                Agence *
                <select class="mt-1 w-full" name="agency_id" x-ref="agencyField">
                    @foreach ($agencies as $agency)
                        <option value="{{ $agency->id }}" @selected(old('agency_id', $vehicle->agency_id) == $agency->id)>{{ $agency->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('agency_id')" />
            </label>
            <label class="text-sm">
                Catégorie *
                <select class="mt-1 w-full" name="vehicle_category_id">
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('vehicle_category_id', $vehicle->vehicle_category_id) == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('vehicle_category_id')" />
            </label>
            @foreach (['registration_number' => 'Immatriculation *', 'vin' => 'VIN', 'brand' => 'Marque *', 'model' => 'Modèle *', 'production_year' => 'Année', 'current_mileage' => 'Kilométrage *'] as $name => $label)
                <label class="text-sm">
                    {{ $label }}
                    <input class="mt-1 w-full" name="{{ $name }}" value="{{ old($name, $vehicle->$name) }}">
                    <x-input-error :messages="$errors->get($name)" />
                </label>
            @endforeach
            <label class="text-sm">
                Couleur
                <input
                    class="mt-1 w-full"
                    name="color"
                    value="{{ $initialColor }}"
                    x-model="colorValue"
                    @input="markColorEdited($event.target.value)"
                    aria-describedby="vehicle-color-help"
                >
                <span id="vehicle-color-help" class="mt-1 block text-xs text-slate-500">La valeur finale reste toujours modifiable.</span>
                <x-input-error :messages="$errors->get('color')" />
            </label>
            <label class="text-sm">
                Carburant *
                <select class="mt-1 w-full" name="fuel_type">
                    @foreach (['petrol', 'diesel', 'hybrid', 'electric', 'other'] as $value)
                        <option value="{{ $value }}" @selected(old('fuel_type', $vehicle->fuel_type) === $value)>{{ App\Support\Ui\UiLabel::get($value) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                Transmission *
                <select class="mt-1 w-full" name="transmission">
                    @foreach (['manual', 'automatic'] as $value)
                        <option value="{{ $value }}" @selected(old('transmission', $vehicle->transmission) === $value)>{{ App\Support\Ui\UiLabel::get($value) }}</option>
                    @endforeach
                </select>
            </label>

            @if ($colorAssistantEnabled)
                <section class="sm:col-span-2 rounded-xl border border-blue-200 bg-blue-50/60 p-4" aria-labelledby="vehicle-color-assistant-title">
                    <h2 id="vehicle-color-assistant-title" class="font-semibold text-slate-950">Photo du véhicule <span class="font-normal text-slate-600">(optionnelle)</span></h2>
                    <p class="mt-1 text-sm text-slate-600">Une suggestion peut compléter le champ Couleur sans bloquer la création du véhicule.</p>
                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label class="min-w-0 flex-1 text-sm font-medium text-slate-800">
                            Choisir une photo
                            <input
                                x-ref="colorPhoto"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white text-sm"
                                @change="message = ''"
                            >
                        </label>
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="busy || !ready"
                            @click="analyze($refs.colorPhoto.files[0], $refs.agencyField.value)"
                        >
                            <span x-show="busy" class="me-2 size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden="true"></span>
                            <span x-text="busy ? 'Analyse en cours…' : 'Analyser la couleur'">Analyser la couleur</span>
                        </button>
                    </div>

                    @unless ($colorAssistantReady)
                        <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">L’analyse de photo est momentanément indisponible. Sélectionnez la couleur manuellement.</p>
                    @endunless

                    <div class="mt-3" aria-live="polite" role="status">
                        <p x-cloak x-show="busy" class="text-sm font-medium text-blue-900">Analyse de la photo en cours…</p>
                        <div x-cloak x-show="phase === 'succeeded' && suggestion" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950">
                            <p><span class="font-semibold">Couleur suggérée :</span> <span x-text="suggestion?.label"></span></p>
                            <p><span class="font-semibold">Confiance indicative :</span> <span x-text="confidenceText()"></span></p>
                            <p class="mt-1" x-text="message"></p>
                            <p class="mt-1">Vous pouvez modifier cette couleur avant l’enregistrement.</p>
                            <button
                                x-cloak
                                x-show="showUseSuggestion"
                                type="button"
                                class="mt-3 rounded-lg border border-emerald-700 px-3 py-2 font-semibold text-emerald-900 hover:bg-emerald-100"
                                @click="useSuggestion()"
                            >Utiliser cette suggestion</button>
                        </div>
                        <p x-cloak x-show="phase === 'failed'" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950" x-text="message"></p>
                    </div>
                </section>
                <input type="hidden" name="color_prediction_run" value="{{ old('color_prediction_run') }}" x-model="acceptedRunId">
                <x-input-error :messages="$errors->get('color_prediction_run')" class="sm:col-span-2" />
            @endif
        </div>
        <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-white">Enregistrer</button>
    </form>
</x-app-layout>
