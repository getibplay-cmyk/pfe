<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Plateforme RentFleet" eyebrow="Administration SaaS" description="Vue agrégée des entreprises clientes, abonnements et services de la plateforme.">
            <x-slot:actions><a href="{{ route('platform.tenants.create') }}" class="rf-button-primary">Créer une entreprise cliente</a></x-slot:actions>
        </x-page-header>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($metrics as $label => $value)<x-stat-card :label="$label" :value="$value" />@endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Dernières entreprises clientes">
                <x-slot:actions><a href="{{ route('platform.tenants.index') }}" class="rf-button-link">Toutes les entreprises</a></x-slot:actions>
                <div class="divide-y divide-slate-100">
                    @forelse($latestTenants as $tenant)
                        <a href="{{ route('platform.tenants.show', $tenant) }}" class="flex items-center justify-between gap-3 py-3 text-sm"><span class="min-w-0"><strong class="block truncate">{{ $tenant->name }}</strong><span class="block truncate text-slate-500">{{ $tenant->slug }}</span></span><x-status-badge :value="$tenant->status" /></a>
                    @empty<x-empty-state title="Aucune entreprise cliente" />@endforelse
                </div>
            </x-section-card>

            <x-section-card title="Alertes d’administration" description="Éléments structurels à compléter pour garantir l’accès au service.">
                <div class="space-y-3">
                    @forelse($alerts as $alert)
                        <a href="{{ route('platform.tenants.show', $alert['tenant']) }}" class="block rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"><strong>{{ $alert['tenant']->name }}</strong><p class="mt-1 text-amber-900">@if($alert['missing_owner'])Aucun administrateur d’entreprise actif. @endif @if($alert['missing_agency'])Aucune agence active.@endif</p></a>
                    @empty<x-flash-message message="Aucune alerte structurelle." />@endforelse
                </div>
            </x-section-card>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Abonnements par état" description="Répartition actuelle, tous plans confondus.">
                <div class="divide-y">@foreach($statistics['subscription_states'] as $state)<div class="flex justify-between gap-4 py-3 text-sm"><span>{{ $state['label'] }}</span><strong>{{ $state['total'] }}</strong></div>@endforeach</div>
            </x-section-card>
            <x-section-card title="Encaissements SaaS manuels sur 30 jours" description="Montants nets séparés par devise ; aucune conversion implicite.">
                @forelse($statistics['payments']['currencies'] as $currency)<div class="flex justify-between gap-4 border-b py-3 text-sm last:border-0"><span>{{ $currency['currency'] }}</span><strong>{{ App\Support\Ui\UiLabel::money($currency['amount'], $currency['currency']) }}</strong></div>@empty<x-empty-state title="Aucun encaissement sur la période" />@endforelse
            </x-section-card>
        </div>

        <x-section-card title="Accès rapides">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <a class="rf-button-secondary justify-center" href="{{ route('platform.tenants.index') }}">Entreprises</a>
                <a class="rf-button-secondary justify-center" href="{{ route('platform.subscriptions.index') }}">Abonnements</a>
                <a class="rf-button-secondary justify-center" href="{{ route('platform.saas-payments.index') }}">Paiements SaaS</a>
                <a class="rf-button-secondary justify-center" href="{{ route('platform.intelligence.index') }}">Assistances intelligentes</a>
                <a class="rf-button-secondary justify-center" href="{{ route('platform.statistics.index') }}">Statistiques détaillées</a>
            </div>
        </x-section-card>
    </div>
</x-app-layout>
