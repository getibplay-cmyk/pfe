<x-app-layout>
    @php
        $tenantTotal = (int) $statistics['totals']['tenants'];
        $activeTenantTotal = (int) $statistics['totals']['active_tenants'];
        $capabilitySlots = $tenantTotal * count($statistics['activations']);
        $subscriptionTotal = collect($statistics['subscription_states'])->sum('total');
        $metricIcons = ['building', 'success', 'warning', 'building', 'users', 'vehicle', 'calendar', 'file', 'payment', 'chart', 'refresh', 'warning'];
        $chartPayload = [
            'tenantStates' => [
                'labels' => collect($statistics['tenant_states'])->pluck('label')->values()->all(),
                'values' => collect($statistics['tenant_states'])->pluck('total')->values()->all(),
            ],
            'subscriptionStates' => [
                'labels' => collect($statistics['subscription_states'])->pluck('label')->values()->all(),
                'values' => collect($statistics['subscription_states'])->pluck('total')->values()->all(),
            ],
            'activity' => $statistics['charts']['activity'],
            'activations' => [
                'labels' => collect($statistics['activations'])->pluck('label')->values()->all(),
                'values' => collect($statistics['activations'])->pluck('tenant_count')->values()->all(),
                'denominator' => $tenantTotal,
            ],
        ];
    @endphp

    <div class="rf-page" data-platform-statistics>
        <x-page-header :title="'Plateforme '.config('brand.name')" eyebrow="Administration SaaS" description="Vue consolidée des entreprises clientes, abonnements et services de la plateforme.">
            <x-slot:actions>
                <a href="{{ route('platform.tenants.create') }}" class="rf-button-primary"><x-icon name="add" size="xs" />Créer une entreprise cliente</a>
            </x-slot:actions>
        </x-page-header>

        <section class="relative overflow-hidden rounded-2xl bg-belkhir-space-ink p-6 text-white shadow-xl shadow-belkhir-space-ink/10">
            <span class="absolute -right-20 -top-24 h-64 w-64 rounded-full border-[3rem] border-belkhir-space-blue/20" aria-hidden="true"></span>
            <span class="absolute -bottom-20 right-28 h-40 w-40 rounded-full border-[2rem] border-belkhir-space-orange/15" aria-hidden="true"></span>
            <div class="relative grid gap-6 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-belkhir-space-orange-soft">Santé de la plateforme</p>
                    <h2 class="mt-2 max-w-xl text-2xl font-bold tracking-tight sm:text-3xl">Les repères essentiels, sans masquer les alertes.</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300">Les deux progressions reposent sur des dénominateurs réels : entreprises enregistrées et six capacités disponibles par entreprise.</p>
                </div>
                <div class="grid gap-5 rounded-2xl border border-white/10 bg-white/5 p-5 backdrop-blur-sm sm:grid-cols-2 [&_[role=progressbar]]:bg-white/15 [&_span]:text-slate-200">
                    @if ($tenantTotal > 0)
                        <x-progress-bar label="Entreprises actives" :value="$activeTenantTotal" :max="$tenantTotal" :value-text="App\Support\Ui\BusinessNumber::integer($activeTenantTotal).' sur '.App\Support\Ui\BusinessNumber::integer($tenantTotal)" tone="orange" />
                    @else
                        <p class="text-sm text-slate-300">Aucune entreprise enregistrée.</p>
                    @endif
                    @if ($capabilitySlots > 0)
                        <x-progress-bar label="Accès aux capacités" :value="$statistics['totals']['enabled_capabilities']" :max="$capabilitySlots" :value-text="App\Support\Ui\BusinessNumber::integer($statistics['totals']['enabled_capabilities']).' sur '.App\Support\Ui\BusinessNumber::integer($capabilitySlots)" />
                    @else
                        <p class="text-sm text-slate-300">Aucune capacité attribuable actuellement.</p>
                    @endif
                </div>
            </div>
        </section>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($metrics as $label => $value)
                <x-stat-card
                    :label="$label"
                    :value="App\Support\Ui\BusinessNumber::integer($value)"
                    :icon="$metricIcons[$loop->index] ?? 'chart'"
                    :tone="$loop->last && (int) $value > 0 ? 'danger' : ($loop->index === 1 ? 'success' : 'brand')"
                />
            @endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_minmax(20rem,0.75fr)]">
            <x-section-card title="Activité des analyses sur 30 jours" description="Nombre réel d’analyses demandées, regroupé par mois.">
                <x-slot:actions><a href="{{ route('platform.statistics.index') }}" class="rf-button-link">Ouvrir les statistiques</a></x-slot:actions>
                <div class="rf-chart-surface h-72 overflow-hidden" data-chart-shell data-chart-ready="false" aria-busy="true">
                    <x-skeleton variant="chart" label="Chargement du graphique d’activité…" class="absolute inset-3 z-10 motion-reduce:animate-none" data-chart-skeleton />
                    <canvas class="opacity-0 transition-opacity duration-500 motion-reduce:transition-none" role="img" data-platform-chart="activity" aria-label="Évolution du nombre d’analyses sur les trente derniers jours" aria-describedby="platform-dashboard-activity-table"></canvas>
                </div>
                <table id="platform-dashboard-activity-table" class="mt-5 w-full text-sm"><caption class="sr-only">Analyses mensuelles sur la période du tableau de bord</caption><thead><tr class="border-b border-slate-200"><th class="py-2 text-left" scope="col">Mois</th><th class="py-2 text-right" scope="col">Analyses</th></tr></thead><tbody>@foreach($statistics['monthly_runs'] as $month)<tr class="border-t border-slate-100"><th class="py-2.5 text-left font-medium" scope="row">{{ $month['label'] }}</th><td class="py-2.5 text-right font-semibold">{{ App\Support\Ui\BusinessNumber::integer($month['total']) }}</td></tr>@endforeach</tbody></table>
            </x-section-card>

            <x-section-card title="Entreprises par état" description="Répartition actuelle des entreprises clientes.">
                <div class="rf-chart-surface h-72 overflow-hidden" data-chart-shell data-chart-ready="false" aria-busy="true">
                    <x-skeleton variant="chart" label="Chargement du graphique des entreprises…" class="absolute inset-3 z-10 motion-reduce:animate-none" data-chart-skeleton />
                    <canvas class="opacity-0 transition-opacity duration-500 motion-reduce:transition-none" role="img" data-platform-chart="tenant-states" aria-label="Répartition des entreprises clientes par état" aria-describedby="platform-dashboard-tenant-table"></canvas>
                </div>
                <table id="platform-dashboard-tenant-table" class="mt-5 w-full text-sm"><caption class="sr-only">Entreprises clientes par état</caption><tbody>@foreach($statistics['tenant_states'] as $state)<tr class="border-t border-slate-100"><th class="py-2.5 text-left font-medium" scope="row">{{ $state['label'] }}</th><td class="py-2.5 text-right font-semibold">{{ App\Support\Ui\BusinessNumber::integer($state['total']) }}</td></tr>@endforeach</tbody></table>
            </x-section-card>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Dernières entreprises clientes">
                <x-slot:actions><a href="{{ route('platform.tenants.index') }}" class="rf-button-link">Toutes les entreprises</a></x-slot:actions>
                <div class="space-y-2">
                    @forelse($latestTenants as $tenant)
                        <a href="{{ route('platform.tenants.show', $tenant) }}" class="group flex items-center justify-between gap-3 rounded-xl border border-transparent p-3 text-sm transition duration-150 hover:-translate-y-px hover:border-belkhir-space-border hover:bg-slate-50 motion-reduce:transform-none motion-reduce:transition-none">
                            <span class="flex min-w-0 items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-belkhir-space-blue/10 text-belkhir-space-blue"><x-icon name="building" size="sm" /></span><span class="min-w-0"><strong class="block truncate text-slate-950">{{ $tenant->name }}</strong><span class="block truncate text-slate-500">{{ $tenant->slug }}</span></span></span>
                            <x-status-badge :value="$tenant->status" />
                        </a>
                    @empty
                        <x-empty-state title="Aucune entreprise cliente" />
                    @endforelse
                </div>
            </x-section-card>

            <x-section-card title="Alertes d’administration" description="Éléments structurels à compléter pour garantir l’accès au service.">
                <div class="space-y-3">
                    @forelse($alerts as $alert)
                        <a href="{{ route('platform.tenants.show', $alert['tenant']) }}" class="group flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm transition duration-150 hover:-translate-y-px hover:shadow-sm motion-reduce:transform-none motion-reduce:transition-none">
                            <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-white text-belkhir-space-warning"><x-icon name="warning" size="xs" /></span>
                            <span><strong class="text-slate-950">{{ $alert['tenant']->name }}</strong><span class="mt-1 block leading-5 text-amber-900">@if($alert['missing_owner'])Aucun administrateur d’entreprise actif. @endif @if($alert['missing_agency'])Aucune agence active.@endif</span></span>
                        </a>
                    @empty
                        <div class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900"><x-icon name="success" /><span>Aucune alerte structurelle.</span></div>
                    @endforelse
                </div>
            </x-section-card>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Abonnements par état" description="Chaque barre est rapportée au nombre réel d’abonnements enregistrés.">
                <div class="space-y-4">
                    @forelse($statistics['subscription_states'] as $state)
                        @if ($subscriptionTotal > 0)
                            <x-progress-bar :label="$state['label']" :value="$state['total']" :max="$subscriptionTotal" :value-text="App\Support\Ui\BusinessNumber::integer($state['total']).' sur '.App\Support\Ui\BusinessNumber::integer($subscriptionTotal)" />
                        @else
                            @break
                        @endif
                    @empty
                    @endforelse
                    @if ($subscriptionTotal === 0)<x-empty-state title="Aucun abonnement enregistré" />@endif
                </div>
            </x-section-card>

            <x-section-card title="Encaissements SaaS manuels sur 30 jours" description="Montants nets séparés par devise ; aucune conversion implicite.">
                <div class="mb-4 grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-belkhir-space-blue/5 p-3"><p class="text-xs text-slate-500">Paiements</p><p class="mt-1 text-xl font-bold text-belkhir-space-blue">{{ App\Support\Ui\BusinessNumber::integer($statistics['payments']['recorded_count']) }}</p></div>
                    <div class="rounded-xl bg-belkhir-space-orange-soft p-3"><p class="text-xs text-slate-600">Contrepassations</p><p class="mt-1 text-xl font-bold text-belkhir-space-orange">{{ App\Support\Ui\BusinessNumber::integer($statistics['payments']['reversal_count']) }}</p></div>
                </div>
                @forelse($statistics['payments']['currencies'] as $currency)
                    <div class="flex justify-between gap-4 border-t border-slate-100 py-3 text-sm"><span class="font-medium">{{ $currency['currency'] }}</span><strong>{{ App\Support\Ui\UiLabel::money($currency['amount'], $currency['currency']) }}</strong></div>
                @empty
                    <x-empty-state title="Aucun encaissement sur la période" />
                @endforelse
            </x-section-card>
        </div>

        <x-section-card title="Accès rapides" description="Rejoignez directement les fonctions d’administration les plus utilisées.">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([
                    ['route' => 'platform.tenants.index', 'label' => 'Entreprises', 'icon' => 'building'],
                    ['route' => 'platform.subscriptions.index', 'label' => 'Abonnements', 'icon' => 'file'],
                    ['route' => 'platform.saas-payments.index', 'label' => 'Paiements', 'icon' => 'payment'],
                    ['route' => 'platform.intelligence.index', 'label' => 'Fonctionnalités intelligentes', 'icon' => 'chart'],
                    ['route' => 'platform.statistics.index', 'label' => 'Statistiques', 'icon' => 'chart'],
                ] as $shortcut)
                    <a href="{{ route($shortcut['route']) }}" class="group flex min-h-24 flex-col justify-between rounded-xl border border-belkhir-space-border bg-white p-4 transition duration-150 hover:-translate-y-0.5 hover:border-belkhir-space-blue/40 hover:shadow-md motion-reduce:transform-none motion-reduce:transition-none">
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-belkhir-space-blue/10 text-belkhir-space-blue"><x-icon :name="$shortcut['icon']" /></span>
                        <span class="mt-4 flex items-center justify-between gap-2 text-sm font-semibold text-slate-900">{{ $shortcut['label'] }}<x-icon name="next" size="xs" class="text-slate-400 transition-transform group-hover:translate-x-0.5 motion-reduce:transform-none" /></span>
                    </a>
                @endforeach
            </div>
        </x-section-card>

        <script type="application/json" data-platform-statistics-payload>{!! json_encode($chartPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    </div>
</x-app-layout>
