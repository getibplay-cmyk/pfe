<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><p class="text-sm text-slate-500">Administration SaaS</p><h1 class="text-3xl font-bold">Plateforme RentFleet</h1></div>
            <a href="{{ route('platform.tenants.create') }}" class="rounded-lg bg-slate-950 px-4 py-2 text-sm text-white">Provisionner un tenant</a>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">@foreach($metrics as $label => $value)<section class="rounded-xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-3xl font-bold">{{ $value }}</p></section>@endforeach</div>
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl bg-white p-6 shadow-sm"><div class="flex items-center justify-between"><h2 class="font-semibold">Derniers tenants</h2><a href="{{ route('platform.tenants.index') }}" class="text-sm text-indigo-700">Tous les tenants</a></div><div class="mt-4 divide-y">@forelse($latestTenants as $tenant)<a href="{{ route('platform.tenants.show', $tenant) }}" class="flex items-center justify-between py-3 text-sm"><span><strong>{{ $tenant->name }}</strong><br><span class="text-slate-500">{{ $tenant->slug }}</span></span><span class="rounded-full bg-slate-100 px-3 py-1">{{ $tenant->status->value }}</span></a>@empty<p class="py-6 text-sm text-slate-500">Aucun tenant.</p>@endforelse</div></section>
            <section class="rounded-xl bg-white p-6 shadow-sm"><h2 class="font-semibold">Alertes d’administration</h2><div class="mt-4 space-y-3">@forelse($alerts as $alert)<a href="{{ route('platform.tenants.show', $alert['tenant']) }}" class="block rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm"><strong>{{ $alert['tenant']->name }}</strong><p class="mt-1 text-amber-900">@if($alert['missing_owner'])Aucun propriétaire actif. @endif @if($alert['missing_agency'])Aucune agence active.@endif</p></a>@empty<p class="rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800">Aucune alerte structurelle.</p>@endforelse</div></section>
        </div>
    </div>
</x-app-layout>
