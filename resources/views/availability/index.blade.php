<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header title="Recherche de disponibilité" eyebrow="Exploitation" description="Recherchez les véhicules opérationnels sans bloc actif sur l’intervalle demandé. Les horaires sont affichés dans le fuseau {{ config('reservations.display_timezone') }}." />
        <x-form-errors />
        <x-filter-panel title="Critères de disponibilité">
            <form method="GET" class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <div><x-input-label for="availability-agency" value="Agence" required /><select id="availability-agency" name="agency_id" required class="mt-1 w-full"><option value="">Sélectionner une agence</option>@foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected(request('agency_id') == $agency->id)>{{ $agency->name }}</option>@endforeach</select><x-field-error :messages="$errors->get('agency_id')" class="mt-2" /></div>
                <div><x-input-label for="availability-category" value="Catégorie" /><select id="availability-category" name="category_id" class="mt-1 w-full"><option value="">Toutes les catégories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>@endforeach</select><x-field-error :messages="$errors->get('category_id')" class="mt-2" /></div>
                <div><x-input-label for="availability-start" value="Début" required /><input id="availability-start" type="datetime-local" name="starts_at" value="{{ request('starts_at') }}" required class="mt-1 w-full"><x-field-error :messages="$errors->get('starts_at')" class="mt-2" /></div>
                <div><x-input-label for="availability-end" value="Fin" required /><input id="availability-end" type="datetime-local" name="ends_at" value="{{ request('ends_at') }}" required class="mt-1 w-full"><x-field-error :messages="$errors->get('ends_at')" class="mt-2" /></div>
                <div class="flex items-end"><x-primary-button class="w-full">Rechercher</x-primary-button></div>
            </form>
        </x-filter-panel>
        @if($vehicles !== null)
            <x-section-card :title="$vehicles->count().' véhicule'.($vehicles->count() > 1 ? 's' : '').' disponible'.($vehicles->count() > 1 ? 's' : '')" description="Résultat calculé à partir des blocs de disponibilité actifs.">
                <div class="grid gap-4 md:grid-cols-2">
                    @forelse($vehicles as $vehicle)
                        <article class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-slate-950">{{ $vehicle->brand }} {{ $vehicle->model }}</h3><p class="mt-1 font-mono text-sm text-slate-600">{{ $vehicle->registration_number }}</p></div><x-status-badge value="active" label="Disponible" /></div>
                            <p class="mt-3 text-sm text-slate-500">{{ $vehicle->category->name }}</p>
                            @can('create', App\Models\Reservation::class)<a class="rf-button-primary mt-4" href="{{ route('reservations.create', ['agency_id' => request('agency_id'), 'vehicle_category_id' => $vehicle->vehicle_category_id, 'vehicle_id' => $vehicle->id, 'starts_at' => request('starts_at'), 'ends_at' => request('ends_at')]) }}">Créer une réservation</a>@endcan
                        </article>
                    @empty
                        <x-empty-state class="md:col-span-2" title="Aucun véhicule disponible" description="Aucun véhicule actif n’est libre sur cet intervalle. Modifiez la période, la catégorie ou l’agence." />
                    @endforelse
                </div>
            </x-section-card>
        @endif
    </div>
</x-app-layout>
