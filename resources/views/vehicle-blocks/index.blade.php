<x-app-layout>
    <div class="space-y-6">
        <x-page-header title="Blocs véhicules" eyebrow="Flotte" description="Les blocs actifs déterminent immédiatement la disponibilité des véhicules.">
            <x-slot:actions>
                @can('create', App\Models\VehicleBlock::class)
                    <a href="{{ route('vehicle-blocks.create') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Nouveau bloc manuel</a>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <x-form-errors />

        <form method="GET" class="grid gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-6">
            <label class="text-sm">Agence
                <select name="agency_id" class="mt-1 w-full">
                    <option value="">Toutes</option>
                    @foreach($agencies as $agency)
                        <option value="{{ $agency->id }}" @selected(request('agency_id') == $agency->id)>{{ $agency->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Véhicule
                <select name="vehicle_id" class="mt-1 w-full">
                    <option value="">Tous</option>
                    @foreach($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}" @selected(request('vehicle_id') == $vehicle->id)>{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Statut
                <select name="status" class="mt-1 w-full">
                    <option value="">Tous</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Type
                <select name="type" class="mt-1 w-full">
                    <option value="">Tous</option>
                    @foreach($types as $type)
                        <option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Du
                <input type="datetime-local" name="starts_at" value="{{ request('starts_at') }}" class="mt-1 w-full">
            </label>
            <label class="text-sm">Au
                <input type="datetime-local" name="ends_at" value="{{ request('ends_at') }}" class="mt-1 w-full">
            </label>
            <div class="flex gap-2 sm:col-span-2 xl:col-span-6">
                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Filtrer</button>
                <a href="{{ route('vehicle-blocks.index') }}" class="rounded-lg border px-4 py-2 text-sm">Réinitialiser</a>
            </div>
        </form>

        <section class="rounded-xl bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold">Historique des indisponibilités</h2>
                <x-result-count :paginator="$blocks" />
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-slate-500"><th class="p-3">Véhicule</th><th class="p-3">Type</th><th class="p-3">Période</th><th class="p-3">Motif</th><th class="p-3">Créateur</th><th class="p-3">Statut</th><th class="p-3"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse($blocks as $block)
                            <tr class="border-t align-top">
                                <td class="p-3"><a class="font-medium text-indigo-700" href="{{ route('vehicles.show', $block->vehicle) }}">{{ $block->vehicle->registration_number }}</a><div class="text-xs text-slate-500">{{ $block->agency->name }}</div></td>
                                <td class="p-3">{{ $block->block_type->label() }}</td>
                                <td class="p-3 whitespace-nowrap">{{ App\Support\Ui\UiLabel::dateTime($block->starts_at) }}<br><span class="text-slate-500">au {{ App\Support\Ui\UiLabel::dateTime($block->ends_at) }}</span></td>
                                <td class="max-w-xs p-3">{{ $block->reason ?: '—' }}</td>
                                <td class="p-3">{{ $block->creator?->name ?? 'Système' }}</td>
                                <td class="p-3"><x-status-badge :value="$block->status" /></td>
                                <td class="p-3">
                                    @can('update', $block)
                                        @if($block->starts_at->isFuture())
                                            <form method="POST" action="{{ route('vehicle-blocks.cancel', $block) }}" onsubmit="return confirm('Annuler ce bloc manuel futur ?')">
                                                @csrf
                                                <button type="submit" class="text-rose-700 underline">Annuler</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('vehicle-blocks.release', $block) }}" onsubmit="return confirm('Libérer ce bloc manuel ?')">
                                                @csrf
                                                <button type="submit" class="text-indigo-700 underline">Libérer</button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-8"><x-empty-state title="Aucun bloc ne correspond aux filtres" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $blocks->links() }}</div>
        </section>
    </div>
</x-app-layout>
