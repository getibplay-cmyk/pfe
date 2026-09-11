<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Planning de flotte" eyebrow="Parc automobile" description="Réservations, locations et immobilisations. Ouvrez une journée pour consulter les horaires et le dossier associé." />
        <x-form-errors />
        <x-filter-panel>
            <form method="GET" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-loading-form>
                <div><label for="planning-date" class="rf-field-label">Premier jour</label><input id="planning-date" name="date" type="date" value="{{ $start->toDateString() }}" class="mt-1 w-full" required></div>
                <div><label for="planning-days" class="rf-field-label">Période</label><select id="planning-days" name="days" class="mt-1 w-full">@foreach([7, 14, 28] as $length)<option value="{{ $length }}" @selected($days === $length)>{{ $length }} jours</option>@endforeach</select></div>
                <div><label for="planning-agency" class="rf-field-label">Agence</label><select id="planning-agency" name="agency_id" class="mt-1 w-full">@if(auth()->user()->agency_id === null)<option value="">Toutes mes agences</option>@endif @foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected($agencyId === $agency->id)>{{ $agency->name }}</option>@endforeach</select></div>
                <div><label for="planning-q" class="rf-field-label">Immatriculation</label><input id="planning-q" name="q" value="{{ request('q') }}" maxlength="50" class="mt-1 w-full" placeholder="Rechercher un véhicule"></div>
                <div><label for="planning-category" class="rf-field-label">Catégorie</label><select id="planning-category" name="category_id" class="mt-1 w-full"><option value="">Toutes les catégories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>@endforeach</select></div>
                <div><label for="planning-status" class="rf-field-label">Statut du véhicule</label><select id="planning-status" name="status" class="mt-1 w-full"><option value="">Tous les statuts</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select></div>
                <div class="flex items-end gap-3"><a class="rf-button-secondary" href="{{ route('fleet.planning.index') }}">Réinitialiser</a></div>
                <div class="flex items-end"><x-primary-button>Afficher le planning</x-primary-button></div>
            </form>
        </x-filter-panel>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a class="rf-button-secondary" href="{{ route('fleet.planning.index', array_merge(request()->except('page'), ['date' => $start->subDays($days)->toDateString()])) }}">← Période précédente</a>
            <a class="rf-button-link" href="{{ route('fleet.planning.index', array_merge(request()->except('page'), ['date' => now($start->timezoneName)->toDateString()])) }}">Aujourd’hui</a>
            <a class="rf-button-secondary" href="{{ route('fleet.planning.index', array_merge(request()->except('page'), ['date' => $end->toDateString()])) }}">Période suivante →</a>
        </div>
        <p class="text-sm text-slate-600">« Sans bloc » indique l’absence de blocage planifié ; le statut du véhicule et les contrôles de réservation restent applicables. Horaires : {{ $start->timezoneName }}.</p>
        <div class="rf-panel overflow-x-auto" role="region" aria-label="Calendrier des véhicules" tabindex="0">
            <table class="w-full border-collapse text-sm">
                <caption class="sr-only">Planning à partir du {{ $start->format('d/m/Y') }} sur {{ $days }} jours</caption>
                <thead><tr><th scope="col" class="sticky left-0 z-10 min-w-48 border-b bg-slate-50 p-3 text-left">Véhicule</th>@foreach($dates as $day)<th scope="col" class="min-w-36 border-b p-3 {{ $day->isToday() ? 'bg-blue-100' : 'bg-slate-50' }}">{{ $day->locale('fr')->translatedFormat('D d/m') }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse($vehicles as $vehicle)
                        <tr>
                            <th scope="row" class="sticky left-0 z-10 border-b bg-white p-3 text-left"><a href="{{ route('vehicles.show', $vehicle) }}" class="rf-button-link">{{ $vehicle->registration_number }}</a><p class="mt-1 font-normal text-slate-500">{{ $vehicle->brand }} {{ $vehicle->model }}</p><p class="font-normal text-slate-500">{{ $vehicle->agency->name }}</p><x-status-badge :value="$vehicle->operational_status" /></th>
                            @foreach($dates as $day)
                                @php($blocks = $vehicle->blocks->filter(fn ($block) => $block->starts_at->lt($day->addDay()) && $block->ends_at->gt($day)))
                                <td class="border-b border-l p-2 align-top {{ $blocks->isEmpty() ? 'bg-emerald-50/40' : 'bg-blue-50/40' }}">
                                    @if($blocks->isEmpty())<span class="text-xs text-slate-500">Sans bloc</span>@else
                                        <details class="rounded-lg border border-blue-200 bg-white p-2">
                                            <summary class="cursor-pointer font-semibold text-blue-900">{{ $blocks->count() === 1 ? App\Support\Ui\UiLabel::blockType($blocks->first()->block_type) : $blocks->count().' occupations' }}</summary>
                                            <ul class="mt-2 space-y-3">@foreach($blocks as $block)
                                                <li><span class="block font-medium">{{ App\Support\Ui\UiLabel::blockType($block->block_type) }}</span><span class="block text-xs text-slate-600">{{ App\Support\Ui\UiLabel::dateTime($block->starts_at) }} → {{ App\Support\Ui\UiLabel::dateTime($block->ends_at) }}</span>
                                                @if($block->rental_contract_id && auth()->user()->hasPermission('contract.view'))<a class="rf-button-link" href="{{ route('contracts.show', $block->rental_contract_id) }}">Ouvrir le contrat</a>
                                                @elseif($block->reservation_id && auth()->user()->hasPermission('reservation.view'))<a class="rf-button-link" href="{{ route('reservations.show', $block->reservation_id) }}">Ouvrir la réservation</a>
                                                @elseif($block->maintenance_order_id && auth()->user()->hasPermission('maintenance.view'))<a class="rf-button-link" href="{{ route('maintenance.show', $block->maintenance_order_id) }}">Ouvrir la maintenance</a>@endif</li>
                                            @endforeach</ul>
                                            @if(auth()->user()->hasPermission('reservation.view'))<a class="rf-button-link mt-2 text-xs" href="{{ route('availability.index', ['agency_id' => $vehicle->agency_id, 'category_id' => $vehicle->vehicle_category_id, 'starts_at' => $day->toIso8601String(), 'ends_at' => $day->addDay()->toIso8601String()]) }}">Chercher une alternative</a>@endif
                                        </details>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty<tr><td colspan="{{ $days + 1 }}" class="p-5"><x-empty-state title="Aucun véhicule dans ce périmètre" description="Modifiez les filtres ou ajoutez votre premier véhicule." /></td></tr>@endforelse
                </tbody>
            </table>
        </div>
        {{ $vehicles->links() }}
    </div>
</x-app-layout>
