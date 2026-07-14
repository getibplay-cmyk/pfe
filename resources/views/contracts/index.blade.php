<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div><p class="text-sm text-slate-500">Lot 04</p><h1 class="text-2xl font-bold">Contrats de location</h1></div>
        <form class="flex flex-wrap gap-3 rounded-xl bg-white p-4 shadow-sm">
            <input name="q" value="{{ request('q') }}" placeholder="Numéro de contrat" class="rounded-lg border-slate-300">
            <select name="status" class="rounded-lg border-slate-300"><option value="">Tous les statuts</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>@endforeach</select>
            <button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Filtrer</button>
        </form>
        <div class="overflow-hidden rounded-xl bg-white shadow-sm"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Contrat</th><th class="p-4">Client</th><th class="p-4">Véhicule</th><th class="p-4">Statut</th><th class="p-4">Période</th></tr></thead><tbody>@forelse($contracts as $contract)<tr class="border-t"><td class="p-4"><a class="font-semibold text-slate-900 underline" href="{{ route('contracts.show', $contract) }}">{{ $contract->contract_number }}</a></td><td class="p-4">{{ $contract->customer->displayName() }}</td><td class="p-4">{{ $contract->vehicle->registration_number }}</td><td class="p-4">{{ $contract->status->label() }}</td><td class="p-4">{{ $contract->expected_start_at->format('d/m/Y H:i') }} — {{ $contract->expected_return_at->format('d/m/Y H:i') }}</td></tr>@empty<tr><td colspan="5" class="p-8 text-center text-slate-500">Aucun contrat.</td></tr>@endforelse</tbody></table></div>
        {{ $contracts->links() }}
    </div>
</x-app-layout>

