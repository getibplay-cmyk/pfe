<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-slate-500">Booking</p>
                <h1 class="text-2xl font-bold">Réservations</h1>
            </div>
            <div class="flex gap-2">
                @if (auth()->user()->hasPermission('reservation.export'))
                    <a href="#export" class="rounded-lg border bg-white px-4 py-2 text-sm">Exporter CSV</a>
                @endif
                @can('create', App\Models\Reservation::class)
                    <a href="{{ route('reservations.create') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Nouvelle réservation</a>
                @endcan
            </div>
        </div>

        <form class="grid gap-3 rounded-xl bg-white p-4 shadow-sm md:grid-cols-4">
            <input name="q" value="{{ request('q') }}" placeholder="Numéro RES-…" class="rounded-lg border-slate-300">
            <select name="agency_id" class="rounded-lg border-slate-300">
                <option value="">Toutes les agences</option>
                @foreach ($agencies as $agency)
                    <option value="{{ $agency->id }}" @selected(request('agency_id') == $agency->id)>{{ $agency->name }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-lg border-slate-300">
                <option value="">Tous les statuts</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
            <button class="rounded-lg bg-slate-800 px-4 py-2 text-white">Filtrer</button>
        </form>

        @if (auth()->user()->hasPermission('reservation.export'))
            <form id="export" method="GET" action="{{ route('reservations.export') }}" class="grid gap-3 rounded-xl border border-indigo-100 bg-indigo-50 p-4 md:grid-cols-4 xl:grid-cols-7">
                <label class="text-sm">Du
                    <input type="date" name="date_from" value="{{ now()->startOfMonth()->toDateString() }}" required class="mt-1 w-full rounded border-slate-300">
                </label>
                <label class="text-sm">Au
                    <input type="date" name="date_to" value="{{ today()->toDateString() }}" required class="mt-1 w-full rounded border-slate-300">
                </label>
                <label class="text-sm">Agence
                    <select name="agency_id" class="mt-1 w-full rounded border-slate-300">
                        <option value="">Toutes</option>
                        @foreach ($agencies as $agency)
                            <option value="{{ $agency->id }}">{{ $agency->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">Statut
                    <select name="status" class="mt-1 w-full rounded border-slate-300">
                        <option value="">Tous</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">Catégorie
                    <select name="vehicle_category_id" class="mt-1 w-full rounded border-slate-300">
                        <option value="">Toutes</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">Véhicule
                    <select name="vehicle_id" class="mt-1 w-full rounded border-slate-300">
                        <option value="">Tous</option>
                        @foreach ($vehicles as $vehicle)
                            <option value="{{ $vehicle->id }}">{{ $vehicle->registration_number }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end">
                    <button class="w-full rounded bg-indigo-700 px-4 py-2 text-white">Télécharger CSV</button>
                </div>
            </form>
        @endif

        <x-result-count :paginator="$reservations" />
        <div class="overflow-x-auto rounded-xl bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr><th class="p-3">Numéro</th><th class="p-3">Client</th><th class="p-3">Agence</th><th class="p-3">Période</th><th class="p-3">Véhicule</th><th class="p-3">Statut</th><th class="p-3">Total</th></tr>
                </thead>
                <tbody>
                    @forelse ($reservations as $reservation)
                        <tr class="border-t">
                            <td class="p-3"><a class="font-medium text-indigo-700" href="{{ route('reservations.show', $reservation) }}">{{ $reservation->reservation_number }}</a></td>
                            <td class="p-3">{{ $reservation->customer->displayName() }}</td>
                            <td class="p-3">{{ $reservation->agency->name }}</td>
                            <td class="p-3">{{ $reservation->starts_at->timezone(config('reservations.display_timezone'))->format('d/m/Y H:i') }}<br><span class="text-slate-400">au {{ $reservation->ends_at->timezone(config('reservations.display_timezone'))->format('d/m/Y H:i') }}</span></td>
                            <td class="p-3">{{ $reservation->vehicle?->registration_number ?? 'À affecter' }}</td>
                            <td class="p-3">{{ $reservation->status->label() }}</td>
                            <td class="p-3">{{ $reservation->total_amount }} {{ $reservation->currency }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-8 text-center text-slate-500">Aucune réservation.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $reservations->links() }}
    </div>
</x-app-layout>
