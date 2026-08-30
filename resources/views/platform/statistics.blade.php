<x-app-layout>
    <div class="rf-page" data-platform-statistics>
        <x-page-header title="Statistiques de la plateforme" eyebrow="Administration SaaS" description="Indicateurs agrégés sur la période sélectionnée, sans donnée personnelle ni détail technique." />

        <x-form-errors />
        <x-filter-panel title="Période observée">
            <form method="GET" action="{{ route('platform.statistics.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
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
                <button type="submit" class="rf-button-primary">Actualiser</button>
            </form>
        </x-filter-panel>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Entreprises" :value="$statistics['totals']['tenants']" />
            <x-stat-card label="Entreprises actives" :value="$statistics['totals']['active_tenants']" tone="success" />
            <x-stat-card label="Paiements SaaS enregistrés" :value="$statistics['totals']['recorded_saas_payments']" />
            <x-stat-card label="Traitements en échec" :value="$statistics['totals']['failed_jobs']" :tone="$statistics['totals']['failed_jobs'] > 0 ? 'danger' : 'success'" />
            <x-stat-card label="Travaux en attente" :value="$statistics['totals']['jobs']" :tone="$statistics['totals']['jobs'] > 0 ? 'warning' : 'success'" />
            <x-stat-card label="Agences" :value="$statistics['totals']['agencies']" />
            <x-stat-card label="Utilisateurs" :value="$statistics['totals']['users']" />
            <x-stat-card label="Véhicules" :value="$statistics['totals']['vehicles']" />
            <x-stat-card label="Réservations / contrats" :value="$statistics['totals']['reservations'].' / '.$statistics['totals']['contracts']" />
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-section-card title="Entreprises et abonnements par état" description="Le tableau reste la référence accessible du graphique.">
                <div class="h-72"><canvas data-platform-chart="states" aria-label="Entreprises et abonnements par état" aria-describedby="platform-states-table"></canvas></div>
                <div id="platform-states-table" class="mt-6 grid gap-4 sm:grid-cols-2">
                    <table class="w-full text-sm"><caption class="mb-2 text-left font-semibold">Entreprises</caption><tbody>@foreach($statistics['tenant_states'] as $state)<tr class="border-t"><th scope="row" class="py-2 text-left font-medium">{{ $state['label'] }}</th><td class="py-2 text-right">{{ $state['total'] }}</td></tr>@endforeach</tbody></table>
                    <table class="w-full text-sm"><caption class="mb-2 text-left font-semibold">Abonnements</caption><tbody>@foreach($statistics['subscription_states'] as $state)<tr class="border-t"><th scope="row" class="py-2 text-left font-medium">{{ $state['label'] }}</th><td class="py-2 text-right">{{ $state['total'] }}</td></tr>@endforeach</tbody></table>
                </div>
            </x-section-card>

            <x-section-card title="Analyses mensuelles" description="Nombre total d’analyses consultatives demandées pendant la période.">
                <div class="h-72"><canvas data-platform-chart="activity" aria-label="Analyses mensuelles" aria-describedby="platform-activity-table"></canvas></div>
                <table id="platform-activity-table" class="mt-6 w-full text-sm"><caption class="sr-only">Analyses mensuelles</caption><thead><tr class="border-b"><th scope="col" class="py-2 text-left">Mois</th><th scope="col" class="py-2 text-right">Analyses</th></tr></thead><tbody>@foreach($statistics['monthly_runs'] as $month)<tr class="border-t"><th scope="row" class="py-2 text-left font-medium">{{ $month['label'] }}</th><td class="py-2 text-right">{{ $month['total'] }}</td></tr>@endforeach</tbody></table>
            </x-section-card>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-section-card title="Encaissements SaaS manuels" description="Montants nets après contrepassations, conservés séparément par devise.">
                @forelse($statistics['payments']['currencies'] as $currency)
                    <div class="flex items-center justify-between border-b py-3 text-sm last:border-0"><span>{{ $currency['currency'] }}</span><strong>{{ App\Support\Ui\UiLabel::money($currency['amount'], $currency['currency']) }}</strong></div>
                @empty<x-empty-state title="Aucun paiement sur cette période" />@endforelse
            </x-section-card>
            <x-section-card title="Capacités intelligentes autorisées" description="Nombre d’entreprises autorisées pour chaque assistance.">
                @foreach($statistics['activations'] as $activation)<div class="flex items-center justify-between border-b py-3 text-sm last:border-0"><span>{{ $activation['label'] }}</span><strong>{{ $activation['tenant_count'] }}</strong></div>@endforeach
            </x-section-card>
        </div>

        <x-responsive-table label="Analyses par capacité et par état">
            <table class="rf-table"><thead><tr><th>Assistance</th><th>État</th><th class="text-right">Nombre</th></tr></thead><tbody>
                @forelse($statistics['run_states'] as $run)<tr><td>{{ $run['label'] }}</td><td><x-status-badge :value="$run['status']" /></td><td class="text-right">{{ $run['total'] }}</td></tr>
                @empty<tr><td colspan="3"><x-empty-state title="Aucune analyse sur cette période" /></td></tr>@endforelse
            </tbody></table>
        </x-responsive-table>

        <script type="application/json" data-platform-statistics-payload>{!! json_encode($statistics['charts'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    </div>
</x-app-layout>
