<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header title="Clients" eyebrow="Relations">
            <x-slot:actions>@can('create', App\Models\Customer::class)<a class="rounded-lg bg-slate-950 px-4 py-2 text-sm text-white" href="{{ route('customers.create') }}">Nouveau client</a>@endcan</x-slot:actions>
        </x-page-header>
        <form method="GET" class="grid gap-3 rounded-xl bg-white p-4 sm:grid-cols-[1fr_auto_auto]">
            <label class="sr-only" for="customer-search">Rechercher</label>
            <input id="customer-search" name="q" value="{{ request('q') }}" placeholder="Nom ou société" class="min-w-0">
            <select name="status" class="min-w-40">
                <option value="active" @selected(request('status', 'active') === 'active')>Actifs</option>
                <option value="archived" @selected(request('status') === 'archived')>Archivés</option>
                <option value="all" @selected(request('status') === 'all')>Tous</option>
            </select>
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Filtrer</button>
        </form>
        <x-result-count :paginator="$customers" />
        <div class="overflow-x-auto rounded-xl bg-white">
            <table class="min-w-full text-left text-sm">
                <thead><tr><th class="p-4">Client</th><th class="p-4">Contact</th><th class="p-4">Identité</th><th class="p-4">Vérification</th><th class="p-4">État</th></tr></thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr class="border-t">
                            <td class="p-4">@if($customer->trashed()){{ $customer->displayName() }}@else<a class="text-blue-700" href="{{ route('customers.show', $customer) }}">{{ $customer->displayName() }}</a>@endif</td>
                            <td class="p-4">{{ $customer->email ?? $customer->phone ?? '—' }}</td>
                            <td class="p-4">{{ $protector->maskEncrypted($customer->identity_number_encrypted) ?? 'Non renseignée' }}</td>
                            <td class="p-4"><x-status-badge :value="$customer->verification_status" /></td>
                            <td class="p-4">
                                @if ($customer->trashed())
                                    <span class="text-slate-500">Archivé</span>
                                    @can('restore', $customer)<form class="mt-2" method="POST" action="{{ route('customers.restore', $customer->id) }}">@csrf<button class="text-indigo-700">Restaurer</button></form>@endcan
                                @else
                                    <span class="text-emerald-700">Actif</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-slate-500">Aucun client ne correspond aux filtres.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $customers->links() }}
    </div>
</x-app-layout>
