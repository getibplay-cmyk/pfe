<x-app-layout>
    @php
        $tenantTotal = (int) $statistics['totals']['tenants'];
        $subscriptionTotal = collect($statistics['subscription_states'])->sum('total');
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
        <x-page-header
            title="Statistiques de la plateforme"
            eyebrow="Administration SaaS"
            :description="'Une lecture consolidée de l’activité '.config('brand.name').', fondée exclusivement sur les données de la période sélectionnée.'"
        />

        <x-form-errors />

        <section class="rf-panel overflow-hidden" aria-labelledby="platform-statistics-filters-title">
            <div class="border-b border-belkhir-space-border bg-slate-50/80 px-5 py-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-belkhir-space-blue/10 text-belkhir-space-blue" aria-hidden="true"><x-icon name="calendar" size="xs" /></span>
                        <div>
                            <h2 id="platform-statistics-filters-title" class="text-sm font-semibold text-slate-950">Période observée</h2>
                            <p class="text-xs text-slate-500">Du {{ $statistics['period']['date_from'] }} au {{ $statistics['period']['date_to'] }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2" aria-label="Filtres actifs">
                        <span class="rounded-full border border-belkhir-space-blue/20 bg-belkhir-space-blue/5 px-3 py-1 text-xs font-medium text-belkhir-space-blue">Début : {{ $statistics['period']['date_from'] }}</span>
                        <span class="rounded-full border border-belkhir-space-orange/20 bg-belkhir-space-orange-soft px-3 py-1 text-xs font-medium text-belkhir-space-orange">Fin : {{ $statistics['period']['date_to'] }}</span>
                    </div>
                </div>
            </div>

            <form
                method="GET"
                action="{{ route('platform.statistics.index') }}"
                class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(11rem,1fr)_minmax(11rem,1fr)_auto_auto] lg:items-end"
                x-data="{ submitting: false }"
                x-on:submit="if (submitting) { $event.preventDefault(); return; } submitting = true"
            >
                <div>
                    <x-input-label for="platform-statistics-date-from" value="Du" />
                    <input id="platform-statistics-date-from" type="date" name="date_from" value="{{ $statistics['period']['date_from'] }}" required class="mt-1 w-full">
                    <x-field-error :messages="$errors->get('date_from')" />
                </div>
                <div>
                    <x-input-label for="platform-statistics-date-to" value="Au" />
                    <input id="platform-statistics-date-to" type="date" name="date_to" value="{{ $statistics['period']['date_to'] }}" required class="mt-1 w-full">
                    <x-field-error :messages="$errors->get('date_to')" />
                </div>
                <button type="submit" class="rf-button-primary min-h-11" x-bind:disabled="submitting" x-bind:aria-busy="submitting.toString()">
                    <x-spinner x-cloak x-show="submitting" size="xs" />
                    <x-icon name="refresh" size="xs" x-show="!submitting" />
                    <span x-text="submitting ? 'Actualisation…' : 'Actualiser'">Actualiser</span>
                </button>
                <a href="{{ route('platform.statistics.index') }}" class="rf-button-quiet min-h-11"><x-icon name="reset" size="xs" />Réinitialiser</a>
            </form>
        </section>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Entreprises clientes" :value="App\Support\Ui\BusinessNumber::integer($tenantTotal)" icon="building" hint="Périmètre total de la plateforme" />
            <x-stat-card
                label="Entreprises actives"
                :value="App\Support\Ui\BusinessNumber::integer($statistics['totals']['active_tenants'])"
                icon="success"
                tone="success"
                :hint="$tenantTotal > 0 ? App\Support\Ui\BusinessNumber::integer($statistics['totals']['active_tenants']).' sur '.App\Support\Ui\BusinessNumber::integer($tenantTotal) : 'Aucune entreprise enregistrée'"
            />
            <x-stat-card
                label="Paiements enregistrés"
                :value="App\Support\Ui\BusinessNumber::integer($statistics['totals']['recorded_saas_payments'])"
                icon="payment"
                :hint="App\Support\Ui\BusinessNumber::count($statistics['payments']['reversal_count'], 'contrepassation').' sur la période'"
            />
            <x-stat-card
                label="Traitements en échec"
                :value="App\Support\Ui\BusinessNumber::integer($statistics['totals']['failed_jobs'])"
                icon="warning"
                :tone="$statistics['totals']['failed_jobs'] > 0 ? 'danger' : 'success'"
                :hint="'Opérations en attente : '.App\Support\Ui\BusinessNumber::integer($statistics['totals']['jobs'])"
            />
        </div>

        <section class="grid gap-4 rounded-2xl bg-belkhir-space-ink p-5 text-white shadow-lg shadow-belkhir-space-ink/10 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] lg:items-center">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-belkhir-space-orange-soft">Couverture plateforme</p>
                <h2 class="mt-2 text-xl font-semibold">Entreprises clientes actives</h2>
                <p class="mt-2 text-sm leading-6 text-slate-300">La progression compare les entreprises actives au nombre total réellement enregistré.</p>
            </div>
            @if ($tenantTotal > 0)
                <div class="rounded-xl border border-white/10 bg-white/5 p-4 [&_[role=progressbar]]:bg-white/15 [&_span]:text-slate-200 [&_span.block]:bg-belkhir-space-orange">
                    <x-progress-bar
                        label="Part des entreprises clientes actives"
                        :value="$statistics['totals']['active_tenants']"
                        :max="$tenantTotal"
                        :value-text="App\Support\Ui\BusinessNumber::integer($statistics['totals']['active_tenants']).' sur '.App\Support\Ui\BusinessNumber::integer($tenantTotal)"
                        tone="orange"
                    />
                </div>
            @else
                <p class="rounded-xl border border-white/15 bg-white/5 p-4 text-sm text-slate-300">Aucune entreprise n’est enregistrée : aucun taux n’est affiché.</p>
            @endif
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-section-card title="Entreprises par état" description="Répartition actuelle des entreprises clientes.">
                <div class="rf-chart-surface h-80 overflow-hidden" data-chart-shell data-chart-ready="false" aria-busy="true">
                    <x-skeleton variant="chart" label="Chargement du graphique des entreprises…" class="absolute inset-3 z-10 motion-reduce:animate-none" data-chart-skeleton />
                    <canvas class="opacity-0 transition-opacity duration-500 motion-reduce:transition-none" role="img" data-platform-chart="tenant-states" aria-label="Répartition des entreprises clientes par état" aria-describedby="platform-tenant-states-table"></canvas>
                </div>
                <div id="platform-tenant-states-table" class="mt-5">
                    <table class="w-full text-sm"><caption class="sr-only">Répartition des entreprises clientes par état</caption><tbody>@foreach($statistics['tenant_states'] as $state)<tr class="border-t border-slate-100"><th scope="row" class="py-2.5 text-left font-medium text-slate-700">{{ $state['label'] }}</th><td class="py-2.5 text-right font-semibold text-slate-950">{{ App\Support\Ui\BusinessNumber::integer($state['total']) }}</td></tr>@endforeach</tbody></table>
                </div>
            </x-section-card>

            <x-section-card title="Abonnements par état" description="Répartition actuelle, tous plans confondus.">
                <div class="rf-chart-surface h-80 overflow-hidden" data-chart-shell data-chart-ready="false" aria-busy="true">
                    <x-skeleton variant="chart" label="Chargement du graphique des abonnements…" class="absolute inset-3 z-10 motion-reduce:animate-none" data-chart-skeleton />
                    <canvas class="opacity-0 transition-opacity duration-500 motion-reduce:transition-none" role="img" data-platform-chart="subscription-states" aria-label="Répartition des abonnements par état" aria-describedby="platform-subscription-states-table"></canvas>
                </div>
                <div id="platform-subscription-states-table" class="mt-5">
                    <div class="mb-3 flex items-center justify-between text-sm"><span class="text-slate-500">Abonnements enregistrés</span><strong>{{ App\Support\Ui\BusinessNumber::integer($subscriptionTotal) }}</strong></div>
                    <table class="w-full text-sm"><caption class="sr-only">Répartition des abonnements par état</caption><tbody>@foreach($statistics['subscription_states'] as $state)<tr class="border-t border-slate-100"><th scope="row" class="py-2.5 text-left font-medium text-slate-700">{{ $state['label'] }}</th><td class="py-2.5 text-right font-semibold text-slate-950">{{ App\Support\Ui\BusinessNumber::integer($state['total']) }}</td></tr>@endforeach</tbody></table>
                </div>
            </x-section-card>

            <x-section-card title="Analyses mensuelles" description="Nombre d’analyses consultatives demandées pendant la période.">
                <div class="rf-chart-surface h-80 overflow-hidden" data-chart-shell data-chart-ready="false" aria-busy="true">
                    <x-skeleton variant="chart" label="Chargement du graphique des analyses…" class="absolute inset-3 z-10 motion-reduce:animate-none" data-chart-skeleton />
                    <canvas class="opacity-0 transition-opacity duration-500 motion-reduce:transition-none" role="img" data-platform-chart="activity" aria-label="Évolution mensuelle du nombre d’analyses" aria-describedby="platform-activity-table"></canvas>
                </div>
                <table id="platform-activity-table" class="mt-5 w-full text-sm"><caption class="sr-only">Analyses mensuelles</caption><thead><tr class="border-b border-slate-200"><th scope="col" class="py-2 text-left">Mois</th><th scope="col" class="py-2 text-right">Analyses</th></tr></thead><tbody>@foreach($statistics['monthly_runs'] as $month)<tr class="border-t border-slate-100"><th scope="row" class="py-2.5 text-left font-medium text-slate-700">{{ $month['label'] }}</th><td class="py-2.5 text-right font-semibold text-slate-950">{{ App\Support\Ui\BusinessNumber::integer($month['total']) }}</td></tr>@endforeach</tbody></table>
            </x-section-card>

            <x-section-card title="Activations par capacité" description="Nombre d’entreprises autorisées, comparé au total réel d’entreprises clientes.">
                <div class="rf-chart-surface h-80 overflow-hidden" data-chart-shell data-chart-ready="false" aria-busy="true">
                    <x-skeleton variant="chart" label="Chargement du graphique des activations…" class="absolute inset-3 z-10 motion-reduce:animate-none" data-chart-skeleton />
                    <canvas class="opacity-0 transition-opacity duration-500 motion-reduce:transition-none" role="img" data-platform-chart="activations" aria-label="Nombre d’entreprises autorisées pour chaque capacité" aria-describedby="platform-activations-table"></canvas>
                </div>
                <div id="platform-activations-table" class="mt-5 overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-sm">
                        <caption class="sr-only">Entreprises autorisées par capacité</caption>
                        <thead><tr class="border-b border-slate-200"><th scope="col" class="py-2 text-left">Capacité</th><th scope="col" class="py-2 text-right">Entreprises</th><th scope="col" class="w-1/2 py-2 pl-5 text-left">Couverture</th></tr></thead>
                        <tbody>
                            @foreach ($statistics['activations'] as $activation)
                                <tr class="border-t border-slate-100">
                                    <th scope="row" class="py-3 text-left font-medium text-slate-700">{{ $activation['label'] }}</th>
                                    <td class="py-3 text-right font-semibold">{{ App\Support\Ui\BusinessNumber::integer($activation['tenant_count']) }} / {{ App\Support\Ui\BusinessNumber::integer($tenantTotal) }}</td>
                                    <td class="py-3 pl-5">
                                        @if ($tenantTotal > 0)
                                            <x-progress-bar
                                                :label="'Couverture de '.$activation['label']"
                                                :value="$activation['tenant_count']"
                                                :max="$tenantTotal"
                                                :value-text="App\Support\Ui\BusinessNumber::integer($activation['tenant_count']).' sur '.App\Support\Ui\BusinessNumber::integer($tenantTotal)"
                                                class="[&>div:first-child]:sr-only"
                                            />
                                        @else
                                            <span class="text-slate-500">Non calculable sans entreprise</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-section-card>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
            <x-section-card title="Encaissements SaaS manuels" description="Montants nets après contrepassations, toujours séparés par devise.">
                <div class="mb-4 grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-belkhir-space-blue/5 p-3"><p class="text-xs text-slate-500">Paiements enregistrés</p><p class="mt-1 text-xl font-bold text-belkhir-space-blue">{{ $statistics['payments']['recorded_count'] }}</p></div>
                    <div class="rounded-xl bg-belkhir-space-orange-soft p-3"><p class="text-xs text-slate-600">Contrepassations</p><p class="mt-1 text-xl font-bold text-belkhir-space-orange">{{ $statistics['payments']['reversal_count'] }}</p></div>
                </div>
                @forelse($statistics['payments']['currencies'] as $currency)
                    <div class="flex items-center justify-between border-t border-slate-100 py-3 text-sm"><span class="font-medium text-slate-700">{{ $currency['currency'] }}</span><strong>{{ App\Support\Ui\UiLabel::money($currency['amount'], $currency['currency']) }}</strong></div>
                @empty
                    <x-empty-state title="Aucun paiement sur cette période" description="Aucun montant n’est représenté sans donnée enregistrée." />
                @endforelse
            </x-section-card>

            <x-responsive-table label="Analyses par capacité et par état">
                <table class="rf-table"><thead><tr><th>Assistance</th><th>État</th><th class="text-right">Nombre</th></tr></thead><tbody>
                    @forelse($statistics['run_states'] as $run)<tr><td>{{ $run['label'] }}</td><td><x-status-badge :value="$run['status']" /></td><td class="text-right font-semibold">{{ $run['total'] }}</td></tr>
                    @empty<tr><td colspan="3"><x-empty-state title="Aucune analyse sur cette période" /></td></tr>@endforelse
                </tbody></table>
            </x-responsive-table>
        </div>

        <script type="application/json" data-platform-statistics-payload>{!! json_encode($chartPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    </div>
</x-app-layout>
