<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header title="Contrats de location" eyebrow="Locations" />
        <form method="GET" class="grid gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-3">
            <label class="sr-only" for="contract-search">Numéro de contrat</label><input id="contract-search" name="q" value="{{ request('q') }}" placeholder="Numéro de contrat">
            <label class="sr-only" for="contract-status">Statut</label><select id="contract-status" name="status"><option value="">Tous les statuts</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select>
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Filtrer</button>
        </form>
        <x-result-count :paginator="$contracts" />
        <div class="overflow-x-auto rounded-xl bg-white shadow-sm"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Contrat</th><th class="p-4">Client</th><th class="p-4">Véhicule</th><th class="p-4">Statut</th><th class="p-4">Période</th></tr></thead><tbody>@forelse($contracts as $contract)<tr class="border-t"><td class="p-4"><a class="font-semibold text-indigo-700" href="{{ route('contracts.show', $contract) }}">{{ $contract->contract_number }}</a></td><td class="p-4">{{ $contract->customer->displayName() }}</td><td class="p-4">{{ $contract->vehicle->registration_number }}</td><td class="p-4"><x-status-badge :value="$contract->status" /></td><td class="p-4">{{ App\Support\Ui\UiLabel::dateTime($contract->expected_start_at) }} — {{ App\Support\Ui\UiLabel::dateTime($contract->expected_return_at) }}</td></tr>@empty<tr><td colspan="5" class="p-8 text-center text-slate-500">Aucun contrat ne correspond aux filtres.</td></tr>@endforelse</tbody></table></div>{{ $contracts->links() }}
    </div>
</x-app-layout>
