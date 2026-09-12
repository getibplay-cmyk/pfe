<x-app-layout>
    <div class="space-y-6">
        <x-page-header :title="__('Blocs véhicules')" :eyebrow="__('Flotte')" :description="__('Les blocs actifs déterminent immédiatement la disponibilité des véhicules.')">
            <x-slot:actions>
                @can('create', App\Models\VehicleBlock::class)
                    <a href="{{ route('vehicle-blocks.create') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">{{ __('Nouveau bloc manuel') }}</a>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <x-form-errors />

        <form method="GET" class="grid gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-6">
            <label class="text-sm">{{ __('Agence') }}
                <select name="agency_id" class="mt-1 w-full">
                    <option value="">{{ __('Toutes') }}</option>
                    @foreach($agencies as $agency)
                        <option value="{{ $agency->id }}" @selected(request('agency_id') == $agency->id)>{{ $agency->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">{{ __('Véhicule') }}
                <select name="vehicle_id" class="mt-1 w-full">
                    <option value="">{{ __('Tous') }}</option>
                    @foreach($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}" @selected(request('vehicle_id') == $vehicle->id)>{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">{{ __('Statut') }}
                <select name="status" class="mt-1 w-full">
                    <option value="">{{ __('Tous') }}</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">{{ __('Type') }}
                <select name="type" class="mt-1 w-full">
                    <option value="">{{ __('Tous') }}</option>
                    @foreach($types as $type)
                        <option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">{{ __('Du') }}
                <input type="datetime-local" name="starts_at" value="{{ request('starts_at') }}" class="mt-1 w-full">
            </label>
            <label class="text-sm">{{ __('Au') }}
                <input type="datetime-local" name="ends_at" value="{{ request('ends_at') }}" class="mt-1 w-full">
            </label>
            <div class="flex gap-2 sm:col-span-2 xl:col-span-6">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white"><x-icon name="filter" size="xs" />{{ __('Filtrer') }}</button>
                <a href="{{ route('vehicle-blocks.index') }}" class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm"><x-icon name="reset" size="xs" />{{ __('Réinitialiser') }}</a>
            </div>
        </form>

        <section class="rounded-xl bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold">{{ __('Historique des indisponibilités') }}</h2>
                <x-result-count :paginator="$blocks" />
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-start text-slate-500"><th class="p-3">{{ __('Véhicule') }}</th><th class="p-3">{{ __('Type') }}</th><th class="p-3">{{ __('Période') }}</th><th class="p-3">{{ __('Motif') }}</th><th class="p-3">{{ __('Créateur') }}</th><th class="p-3">{{ __('Statut') }}</th><th class="p-3"><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
                    <tbody>
                        @forelse($blocks as $block)
                            <tr class="border-t align-top">
                                <td class="p-3"><a class="font-medium text-indigo-700" href="{{ route('vehicles.show', $block->vehicle) }}">{{ $block->vehicle->registration_number }}</a><div class="text-xs text-slate-500">{{ $block->agency->name }}</div></td>
                                <td class="p-3">{{ $block->block_type->label() }}</td>
                                <td class="p-3 whitespace-nowrap">{{ App\Support\Ui\UiLabel::dateTime($block->starts_at) }}<br><span class="text-slate-500">{{ __('au') }} {{ App\Support\Ui\UiLabel::dateTime($block->ends_at) }}</span></td>
                                <td class="max-w-xs p-3">{{ $block->reason ?: '—' }}</td>
                                <td class="p-3">{{ $block->creator?->name ?? __('Système') }}</td>
                                <td class="p-3"><x-status-badge :value="$block->status" /></td>
                                <td class="p-3">
                                    @can('update', $block)
                                        @if($block->starts_at->isFuture())
                                            <form method="POST" action="{{ route('vehicle-blocks.cancel', $block) }}" x-belkhir-space-confirm data-confirm-title="{{ __('Annuler le bloc manuel') }}" data-confirm-resource="{{ __('Bloc véhicule sélectionné') }}" data-confirm-consequence="{{ __('Ce bloc manuel futur sera annulé et la disponibilité sera recalculée selon les règles existantes.') }}" data-confirm-label="{{ __('Annuler le bloc') }}">
                                                @csrf
                                                <button type="submit" class="text-rose-700 underline">{{ __('Annuler') }}</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('vehicle-blocks.release', $block) }}" x-belkhir-space-confirm data-confirm-title="{{ __('Libérer le bloc manuel') }}" data-confirm-resource="{{ __('Bloc véhicule sélectionné') }}" data-confirm-consequence="{{ __('Ce bloc manuel sera libéré selon les règles de disponibilité existantes.') }}" data-confirm-label="{{ __('Libérer le bloc') }}">
                                                @csrf
                                                <button type="submit" class="text-indigo-700 underline">{{ __('Libérer') }}</button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-8"><x-empty-state :title="__('Aucun bloc ne correspond aux filtres')" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $blocks->links() }}</div>
        </section>
    </div>
</x-app-layout>
