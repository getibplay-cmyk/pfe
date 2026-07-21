<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Plateforme RentFleet" eyebrow="Administration SaaS" description="Vue structurelle des organisations clientes, sans accès à leurs données métier.">
            <x-slot:actions><a href="{{ route('platform.tenants.create') }}" class="rf-button-primary">Provisionner un tenant</a></x-slot:actions>
        </x-page-header>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">@foreach($metrics as $label => $value)<x-stat-card :label="$label" :value="$value" />@endforeach</div>
        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Derniers tenants"><x-slot:actions><a href="{{ route('platform.tenants.index') }}" class="rf-button-link">Tous les tenants</a></x-slot:actions><div class="divide-y divide-slate-100">@forelse($latestTenants as $tenant)<a href="{{ route('platform.tenants.show', $tenant) }}" class="flex items-center justify-between gap-3 py-3 text-sm"><span class="min-w-0"><strong class="block truncate">{{ $tenant->name }}</strong><span class="block truncate text-slate-500">{{ $tenant->slug }}</span></span><x-status-badge :value="$tenant->status" /></a>@empty<x-empty-state title="Aucun tenant" />@endforelse</div></x-section-card>
            <x-section-card title="Alertes d’administration" description="Contrôles de structure nécessaires au fonctionnement d’un tenant."><div class="space-y-3">@forelse($alerts as $alert)<a href="{{ route('platform.tenants.show', $alert['tenant']) }}" class="block rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"><strong>{{ $alert['tenant']->name }}</strong><p class="mt-1 text-amber-900">@if($alert['missing_owner'])Aucun propriétaire actif. @endif @if($alert['missing_agency'])Aucune agence active.@endif</p></a>@empty<x-flash-message message="Aucune alerte structurelle." />@endforelse</div></x-section-card>
        </div>
    </div>
</x-app-layout>
