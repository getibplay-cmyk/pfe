<x-app-layout>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-slate-500">Flotte</p>
                <h1 class="text-3xl font-bold">Maintenance</h1>
                <p class="mt-2 text-sm text-slate-600">L’approbation crée un bloc d’indisponibilité protégé par PostgreSQL.</p>
            </div>
            @if(auth()->user()->hasPermission('maintenance.create'))
                <a href="{{ route('maintenance.create') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Nouvel ordre</a>
            @endif
        </div>

        <section class="rounded-xl bg-white p-6 shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-slate-500"><th class="py-2">Ordre</th><th>Véhicule</th><th>Objet</th><th>Période</th><th>Statut</th><th></th></tr></thead>
                    <tbody>
                    @forelse($orders as $order)
                        <tr class="border-t">
                            <td class="py-3 font-medium">{{ $order->maintenance_number }}</td>
                            <td>{{ $order->vehicle->registration_number }}</td>
                            <td>{{ $order->title }}</td>
                            <td>{{ $order->scheduled_start_at?->format('d/m/Y H:i') ?? 'Non planifiée' }} → {{ $order->scheduled_end_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td><span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium">{{ str_replace('_', ' ', $order->status) }}</span></td>
                            <td class="text-right"><a href="{{ route('maintenance.show', $order) }}" class="text-indigo-700 underline">Ouvrir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">Aucun ordre de maintenance.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $orders->links() }}</div>
        </section>
    </div>
</x-app-layout>
