<x-app-layout>
    <div class="rf-page">
        <x-page-header :title="__('Planning de flotte')" :eyebrow="__('Parc automobile')" :description="__('Réservations, locations et immobilisations. Ouvrez une journée pour consulter les horaires et le dossier associé.')" />
        <x-form-errors />
        <x-filter-panel>
            <form method="GET" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-loading-form>
                <div><label for="planning-date" class="rf-field-label">{{ __('Premier jour') }}</label><input id="planning-date" name="date" type="date" value="{{ $start->toDateString() }}" class="mt-1 w-full" required></div>
                <div><label for="planning-days" class="rf-field-label">{{ __('Période') }}</label><select id="planning-days" name="days" class="mt-1 w-full">@foreach([7, 14, 28] as $length)<option value="{{ $length }}" @selected($days === $length)>{{ $length }} {{ __('jours') }}</option>@endforeach</select></div>
                <div><label for="planning-agency" class="rf-field-label">{{ __('Agence') }}</label><select id="planning-agency" name="agency_id" class="mt-1 w-full">@if(auth()->user()->agency_id === null)<option value="">{{ __('Toutes mes agences') }}</option>@endif @foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected($agencyId === $agency->id)>{{ $agency->name }}</option>@endforeach</select></div>
                <div><label for="planning-q" class="rf-field-label">{{ __('Immatriculation') }}</label><input id="planning-q" name="q" value="{{ request('q') }}" maxlength="50" class="mt-1 w-full" placeholder="{{ __('Rechercher un véhicule') }}"></div>
                <div><label for="planning-category" class="rf-field-label">{{ __('Catégorie') }}</label><select id="planning-category" name="category_id" class="mt-1 w-full"><option value="">{{ __('Toutes les catégories') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>@endforeach</select></div>
                <div><label for="planning-status" class="rf-field-label">{{ __('Statut du véhicule') }}</label><select id="planning-status" name="status" class="mt-1 w-full"><option value="">{{ __('Tous les statuts') }}</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select></div>
                <div class="flex items-end gap-3"><a class="rf-button-secondary" href="{{ route('fleet.planning.index') }}">{{ __('Réinitialiser') }}</a></div>
                <div class="flex items-end"><x-primary-button>{{ __('Afficher le planning') }}</x-primary-button></div>
            </form>
        </x-filter-panel>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a class="rf-button-secondary" href="{{ route('fleet.planning.index', array_merge(request()->except('page'), ['date' => $start->subDays($days)->toDateString()])) }}">{{ __('← Période précédente') }}</a>
            <a class="rf-button-link" href="{{ route('fleet.planning.index', array_merge(request()->except('page'), ['date' => now($start->timezoneName)->toDateString()])) }}">{{ __('Aujourd’hui') }}</a>
            <a class="rf-button-secondary" href="{{ route('fleet.planning.index', array_merge(request()->except('page'), ['date' => $end->toDateString()])) }}">{{ __('Période suivante →') }}</a>
        </div>
        <p class="text-sm text-slate-600">{{ __('« Sans bloc » indique l’absence de blocage planifié ; le statut du véhicule et les contrôles de réservation restent applicables. Horaires :') }} {{ $start->timezoneName }}.</p>
        @if($canMove)<p class="rounded-lg bg-brand-50 p-3 text-sm">{{ __('Glissez une réservation vers une autre journée ou un véhicule de la même agence. Sur mobile ou au clavier, utilisez le lien Déplacer. Un aperçu sera présenté avant confirmation.') }}</p>@endif
        <form data-replan-drop-form method="POST" hidden>@csrf<input type="hidden" name="vehicle_id"><input type="hidden" name="shift_days"></form>
        <div data-fleet-planning class="rf-panel overflow-x-auto" role="region" aria-label="{{ __('Calendrier des véhicules') }}" tabindex="0">
            <table class="w-full border-collapse text-sm">
                <caption class="sr-only">{{ __('Planning à partir du') }} {{ $start->format('d/m/Y') }} {{ __('sur') }} {{ $days }} {{ __('jours') }}</caption>
                <thead><tr><th scope="col" class="sticky start-0 z-10 min-w-48 border-b bg-slate-50 p-3 text-start">{{ __('Véhicule') }}</th>@foreach($dates as $day)<th scope="col" class="min-w-36 border-b p-3 {{ $day->isToday() ? 'bg-blue-100' : 'bg-slate-50' }}">{{ $day->locale(app()->getLocale())->translatedFormat('D d/m') }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse($vehicles as $vehicle)
                        <tr>
                            <th scope="row" class="sticky start-0 z-10 border-b bg-white p-3 text-start"><a href="{{ route('vehicles.show', $vehicle) }}" class="rf-button-link">{{ $vehicle->registration_number }}</a><p class="mt-1 font-normal text-slate-500">{{ $vehicle->brand }} {{ $vehicle->model }}</p><p class="font-normal text-slate-500">{{ $vehicle->agency->name }}</p><x-status-badge :value="$vehicle->operational_status" /></th>
                            @foreach($dates as $day)
                                @php($blocks = $vehicle->blocks->filter(fn ($block) => $block->starts_at->lt($day->addDay()) && $block->ends_at->gt($day)))
                                <td @if($canMove && $vehicle->operational_status->value === 'active') data-replan-target="{{ $vehicle->id }}" data-agency="{{ $vehicle->agency_id }}" data-day="{{ $day->toDateString() }}" @endif class="border-b border-l p-2 align-top {{ $blocks->isEmpty() ? 'bg-emerald-50/40' : 'bg-blue-50/40' }}">
                                    @if($blocks->isEmpty())<span class="text-xs text-slate-500">{{ __('Sans bloc') }}</span>@else
                                        <details class="rounded-lg border border-blue-200 bg-white p-2">
                                            <summary @if($canMove && $blocks->count() === 1 && $blocks->first()->reservation?->status?->value === 'confirmed') data-replan-source="{{ $blocks->first()->reservation_id }}" data-agency="{{ $vehicle->agency_id }}" data-day="{{ $day->toDateString() }}" data-preview-url="{{ route('fleet.planning.preview', $blocks->first()->reservation_id) }}" @endif class="cursor-pointer font-semibold text-blue-900">{{ $blocks->count() === 1 ? App\Support\Ui\UiLabel::blockType($blocks->first()->block_type) : $blocks->count().' occupations' }}</summary>
                                            <ul class="mt-2 space-y-3">@foreach($blocks as $block)
                                                <li><span class="block font-medium">{{ App\Support\Ui\UiLabel::blockType($block->block_type) }}</span><span class="block text-xs text-slate-600">{{ App\Support\Ui\UiLabel::dateTime($block->starts_at) }} → {{ App\Support\Ui\UiLabel::dateTime($block->ends_at) }}</span>
                                                @if($block->rental_contract_id && auth()->user()->hasPermission('contract.view'))<a class="rf-button-link" href="{{ route('contracts.show', $block->rental_contract_id) }}">{{ __('Ouvrir le contrat') }}</a>
                                                @elseif($block->reservation_id && auth()->user()->hasPermission('reservation.view'))<a class="rf-button-link" href="{{ route('reservations.show', $block->reservation_id) }}">{{ __('Ouvrir la réservation') }}</a>
                                                @if($canMove && $block->reservation?->status?->value === 'confirmed')<a data-replan-source="{{ $block->reservation_id }}" data-agency="{{ $vehicle->agency_id }}" data-day="{{ $day->toDateString() }}" data-preview-url="{{ route('fleet.planning.preview', $block->reservation_id) }}" class="rf-button-link" href="{{ route('fleet.planning.edit', $block->reservation_id) }}">{{ __('Déplacer') }}</a>@endif
                                                @elseif($block->maintenance_order_id && auth()->user()->hasPermission('maintenance.view'))<a class="rf-button-link" href="{{ route('maintenance.show', $block->maintenance_order_id) }}">{{ __('Ouvrir la maintenance') }}</a>@endif</li>
                                            @endforeach</ul>
                                            @if(auth()->user()->hasPermission('reservation.view'))<a class="rf-button-link mt-2 text-xs" href="{{ route('availability.index', ['agency_id' => $vehicle->agency_id, 'category_id' => $vehicle->vehicle_category_id, 'starts_at' => $day->toIso8601String(), 'ends_at' => $day->addDay()->toIso8601String()]) }}">{{ __('Chercher une alternative') }}</a>@endif
                                        </details>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty<tr><td colspan="{{ $days + 1 }}" class="p-5"><x-empty-state :title="__('Aucun véhicule dans ce périmètre')" :description="__('Modifiez les filtres ou ajoutez votre premier véhicule.')" /></td></tr>@endforelse
                </tbody>
            </table>
        </div>
        {{ $vehicles->links() }}
    </div>
</x-app-layout>
