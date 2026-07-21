<x-app-layout>
    <div class="space-y-6">
        <x-page-header title="Maintenance" eyebrow="Flotte" description="L’approbation crée un bloc d’indisponibilité protégé par PostgreSQL.">
            <x-slot:actions>@if(auth()->user()->hasPermission('maintenance.create'))<a href="{{ route('maintenance.create') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Nouvel ordre</a>@endif</x-slot:actions>
        </x-page-header>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">@foreach($summary as $label => $value)<x-stat-card :label="$label" :value="$value" />@endforeach</div>
        <form method="GET" class="grid gap-3 rounded-xl bg-white p-4 sm:grid-cols-3"><input name="q" value="{{ request('q') }}" placeholder="Numéro ou objet" aria-label="Recherche"><select name="status" aria-label="Statut"><option value="">Tous les statuts</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select><button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Filtrer</button></form>
        <x-result-count :paginator="$orders" />
        <x-responsive-table label="Ordres de maintenance"><table><thead><tr><th>Ordre</th><th>Véhicule</th><th>Objet</th><th>Période</th><th>Statut</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>@forelse($orders as $order)<tr><td class="font-medium">{{ $order->maintenance_number }}</td><td>{{ $order->vehicle->registration_number }}</td><td>{{ $order->title }}</td><td>{{ App\Support\Ui\UiLabel::dateTime($order->scheduled_start_at) }} → {{ App\Support\Ui\UiLabel::dateTime($order->scheduled_end_at) }}</td><td><x-status-badge :value="$order->status" /></td><td class="text-right"><a href="{{ route('maintenance.show', $order) }}" class="text-indigo-700">Ouvrir</a></td></tr>@empty<tr><td colspan="6" class="p-10 text-center text-slate-500">Aucun ordre de maintenance ne correspond aux filtres.</td></tr>@endforelse</tbody></table></x-responsive-table>{{ $orders->links() }}
    </div>
</x-app-layout>
