<x-app-layout>
    @php
        $activeFilterCount = collect(['q', 'status'])
            ->filter(fn (string $key): bool => request()->filled($key))
            ->count();
    @endphp
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header :title="__('Clients')" :eyebrow="__('Relations')">
            <x-slot:actions>@can('create', App\Models\Customer::class)<a class="rounded-lg bg-slate-950 px-4 py-2 text-sm text-white" href="{{ route('customers.create') }}">{{ __('Nouveau client') }}</a>@endcan</x-slot:actions>
        </x-page-header>
        <x-filter-panel :title="__('Rechercher un client')" :active-count="$activeFilterCount" :result-count="$customers->total()">
        <form method="GET" class="grid gap-3 sm:grid-cols-[1fr_auto_auto]" data-loading-form>
            <label class="sr-only" for="customer-search">{{ __('Rechercher') }}</label>
            <input id="customer-search" name="q" value="{{ request('q') }}" placeholder="{{ __('Nom ou société') }}" class="min-w-0">
            <select name="status" class="min-w-40">
                <option value="active" @selected(request('status', 'active') === 'active')>{{ __('Actifs') }}</option>
                <option value="archived" @selected(request('status') === 'archived')>{{ __('Archivés') }}</option>
                <option value="all" @selected(request('status') === 'all')>{{ __('Tous') }}</option>
            </select>
            <div class="flex gap-2"><x-submit-button :label="__('Appliquer')" loading-label="{{ __('Recherche…') }}" />@if($activeFilterCount > 0)<a href="{{ route('customers.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />{{ __('Réinitialiser') }}</a>@endif</div>
        </form>
        </x-filter-panel>
        <x-result-count :paginator="$customers" />
        <div class="overflow-x-auto rounded-xl bg-white">
            <table class="min-w-full text-start text-sm">
                <thead><tr><th class="p-4">{{ __('Client') }}</th><th class="p-4">{{ __('Contact') }}</th><th class="p-4">{{ __('Identité') }}</th><th class="p-4">{{ __('Vérification') }}</th><th class="p-4">{{ __('État') }}</th></tr></thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr class="border-t">
                            <td class="p-4">@if($customer->trashed()){{ $customer->displayName() }}@else<a class="text-blue-700" href="{{ route('customers.show', $customer) }}">{{ $customer->displayName() }}</a>@endif</td>
                            <td class="p-4">{{ $customer->email ?? $customer->phone ?? '—' }}</td>
                            <td class="p-4">{{ $protector->maskEncrypted($customer->identity_number_encrypted) ?? __('Non renseignée') }}</td>
                            <td class="p-4"><x-status-badge :value="$customer->verification_status" /></td>
                            <td class="p-4">
                                @if ($customer->trashed())
                                    <span class="text-slate-500">{{ __('Archivé') }}</span>
                                    @can('restore', $customer)<form class="mt-2" method="POST" action="{{ route('customers.restore', $customer->id) }}">@csrf<button class="text-indigo-700">{{ __('Restaurer') }}</button></form>@endcan
                                @else
                                    <span class="text-emerald-700">{{ __('Actif') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-slate-500">{{ __('Aucun client ne correspond aux filtres.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $customers->links() }}
    </div>
</x-app-layout>
