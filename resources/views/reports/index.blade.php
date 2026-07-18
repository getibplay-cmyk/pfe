<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Rapports opérationnels et financiers"
            eyebrow="Pilotage"
            description="Indicateurs explicables de votre périmètre autorisé. Les montants restent séparés par devise."
        >
            <x-slot:actions>
                <a href="{{ route('reports.export', request()->query()) }}" class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                    Exporter le résumé CSV
                </a>
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('reports.index') }}" class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2 xl:grid-cols-5">
            <label class="text-sm font-medium text-slate-700">Du
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" required class="mt-1 w-full rounded-lg border-slate-300">
            </label>
            <label class="text-sm font-medium text-slate-700">Au
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" required class="mt-1 w-full rounded-lg border-slate-300">
            </label>
            <label class="text-sm font-medium text-slate-700">Agence
                <select name="agency_id" class="mt-1 w-full rounded-lg border-slate-300">
                    @if ($agencies->count() > 1)<option value="">Toutes les agences autorisées</option>@endif
                    @foreach ($agencies as $agency)
                        <option value="{{ $agency->id }}" @selected(($filters['agency_id'] ?? null) == $agency->id)>{{ $agency->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-medium text-slate-700">Devise
                <select name="currency" class="mt-1 w-full rounded-lg border-slate-300">
                    <option value="">Toutes, séparées</option>
                    @foreach ($report['meta']['available_currencies'] as $currency)
                        <option value="{{ $currency }}" @selected(($filters['currency'] ?? null) === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end"><button class="w-full rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-600">Actualiser</button></div>
        </form>

        @if ($errors->any())<x-form-errors />@endif

        <section class="rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-950">
            <p class="font-semibold">Filtres actifs</p>
            <p class="mt-1">
                Du {{ $report['meta']['date_from'] }} au {{ $report['meta']['date_to'] }} inclus côté interface,
                soit <span class="font-mono">[{{ $report['meta']['period_start'] }}, {{ $report['meta']['period_end_exclusive'] }})</span>,
                fuseau {{ $report['meta']['timezone'] }} · {{ $selectedAgencyNames->join(', ') }} ·
                {{ $filters['currency'] ?? 'toutes les devises, sans consolidation' }}.
            </p>
        </section>

        <section class="space-y-4">
            <h2 class="text-xl font-semibold text-slate-900">Exploitation</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($report['operational']['reservations'] as $key => $value)
                    <x-stat-card :label="\App\Support\Ui\UiLabel::report('reservations.'.$key)" :value="$value" />
                @endforeach
            </div>
            <div class="grid gap-6 lg:grid-cols-2">
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4"><h3 class="font-semibold">Contrats et retours</h3></div>
                    <dl class="divide-y divide-slate-100">
                        @foreach ($report['operational']['contracts'] as $key => $value)
                            <div class="flex items-center justify-between px-5 py-3 text-sm"><dt class="text-slate-600">{{ \App\Support\Ui\UiLabel::report('contracts.'.$key) }}</dt><dd class="font-semibold">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4"><h3 class="font-semibold">Maintenance, assurance et échéances</h3></div>
                    <dl class="divide-y divide-slate-100">
                        @foreach ($report['operational']['maintenance'] as $key => $value)
                            <div class="flex items-center justify-between px-5 py-3 text-sm"><dt class="text-slate-600">{{ \App\Support\Ui\UiLabel::report('maintenance.'.$key) }}</dt><dd class="font-semibold">{{ $value }}</dd></div>
                        @endforeach
                        <div class="flex items-center justify-between px-5 py-3 text-sm"><dt class="text-slate-600">{{ \App\Support\Ui\UiLabel::report('insurance.open_claims') }}</dt><dd class="font-semibold">{{ $report['operational']['insurance']['open_claims'] }}</dd></div>
                        <div class="flex items-center justify-between px-5 py-3 text-sm"><dt class="text-slate-600">{{ \App\Support\Ui\UiLabel::report('expirations.documents') }}</dt><dd class="font-semibold">{{ $report['operational']['expirations']['documents'] }}</dd></div>
                        <div class="flex items-center justify-between px-5 py-3 text-sm"><dt class="text-slate-600">{{ \App\Support\Ui\UiLabel::report('expirations.driving_licences') }}</dt><dd class="font-semibold">{{ $report['operational']['expirations']['driving_licences'] }}</dd></div>
                    </dl>
                </article>
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="text-xl font-semibold text-slate-900">Flotte</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (array_diff_key($report['operational']['fleet'], ['snapshot_at' => true]) as $key => $value)
                    <x-stat-card :label="\App\Support\Ui\UiLabel::report('fleet.'.$key)" :value="$value" />
                @endforeach
            </div>
            <div class="grid gap-6 lg:grid-cols-2">
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">{{ \App\Support\Ui\UiLabel::report('utilization.rate') }}</p>
                    <p class="mt-2 text-3xl font-bold">{{ $report['operational']['utilization']['rate'] }} %</p>
                    <p class="mt-2 text-sm text-slate-600">{{ $report['operational']['utilization']['occupied_duration'] }} bloquées sur {{ number_format(intdiv($report['operational']['utilization']['capacity_seconds'], 3600), 0, ',', ' ') }} heures de capacité.</p>
                    <p class="mt-3 text-xs text-slate-500">Les blocs actifs réservation, contrat, manuel et maintenance sont bornés à leur intersection réelle avec la période. Les blocs libérés ou annulés sont exclus.</p>
                </article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4"><h3 class="font-semibold">Durée par type de bloc</h3></div>
                    <dl class="divide-y divide-slate-100">
                        @foreach ($report['operational']['utilization']['block_types'] as $type => $seconds)
                            <div class="flex items-center justify-between px-5 py-3 text-sm"><dt>{{ \App\Support\Ui\UiLabel::get($type) }}</dt><dd class="font-medium">{{ intdiv($seconds, 3600) }} h {{ str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT) }} min</dd></div>
                        @endforeach
                    </dl>
                </article>
            </div>
        </section>

        <section class="space-y-4">
            <div><h2 class="text-xl font-semibold text-slate-900">Finance par devise</h2><p class="text-sm text-slate-600">Aucune conversion ni addition entre devises. Les paiements en attente sont exclus.</p></div>
            @forelse ($report['financial']['currencies'] as $currency => $values)
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4"><h3 class="font-semibold">Devise {{ $currency }}</h3><span class="text-sm text-slate-500">{{ $values['issued_invoices'] }} facture(s) émise(s)</span></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="px-5 py-3">Indicateur</th><th class="px-5 py-3 text-right">Valeur</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach (['invoiced_amount', 'collected_net', 'outstanding_balance', 'held_deposits', 'retained_deposits', 'refunded_deposits', 'approved_expenses'] as $key)
                                    <tr><td class="px-5 py-3">{{ \App\Support\Ui\UiLabel::report('finance.'.$key) }}</td><td class="px-5 py-3 text-right font-medium">{{ \App\Support\Ui\UiLabel::money($values[$key], $currency) }}</td></tr>
                                @endforeach
                                @foreach ($values['expenses'] as $status => $count)
                                    <tr><td class="px-5 py-3">{{ \App\Support\Ui\UiLabel::report('expenses.'.$status) }}</td><td class="px-5 py-3 text-right font-medium">{{ $count }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>
            @empty
                <x-empty-state title="Aucune donnée financière" description="Aucun mouvement ne correspond aux filtres sélectionnés." />
            @endforelse
        </section>

        <section class="space-y-4">
            <div><h2 class="text-xl font-semibold text-slate-900">Réservations intersectant la période</h2><p class="text-sm text-slate-600">Liste bornée et paginée, sans identité ni document privé.</p></div>
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                @if ($reservationRows->isEmpty())
                    <x-empty-state title="Aucune réservation" description="Aucune réservation n’intersecte cette période." />
                @else
                    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-slate-600"><tr><th class="px-4 py-3">Numéro</th><th class="px-4 py-3">Agence</th><th class="px-4 py-3">Catégorie</th><th class="px-4 py-3">Période</th><th class="px-4 py-3">Statut</th><th class="px-4 py-3 text-right">Montant</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach ($reservationRows as $reservation)<tr><td class="px-4 py-3 font-medium">{{ $reservation->reservation_number }}</td><td class="px-4 py-3">{{ $reservation->agency->name }}</td><td class="px-4 py-3">{{ $reservation->vehicleCategory->name }}</td><td class="px-4 py-3">{{ \App\Support\Ui\UiLabel::dateTime($reservation->starts_at) }} – {{ \App\Support\Ui\UiLabel::dateTime($reservation->ends_at) }}</td><td class="px-4 py-3"><x-status-badge :value="$reservation->status" /></td><td class="px-4 py-3 text-right">{{ \App\Support\Ui\UiLabel::money($reservation->total_amount, $reservation->currency) }}</td></tr>@endforeach</tbody></table></div>
                    <div class="border-t border-slate-100 px-4 py-3">{{ $reservationRows->links() }}</div>
                @endif
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-700 shadow-sm">
            <h2 class="font-semibold text-slate-900">Lecture des indicateurs</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5">
                <li>La période est semi-ouverte : début inclus, fin exclusive. Deux périodes consécutives ne comptent jamais deux fois la même frontière.</li>
                <li>Le taux d’utilisation divise les secondes de blocs actifs par les secondes de capacité des véhicules aux états actif ou maintenance, selon leur historique de statut.</li>
                <li>L’encaissement net additionne les allocations comptabilisées pendant la période et soustrait les allocations de contrepassation ; les paiements en attente sont exclus.</li>
                <li>Le solde dû correspond aux factures émises non annulées de la période moins leurs allocations nettes comptabilisées avant la fin exclusive.</li>
                <li>Ces indicateurs internes ne constituent ni une comptabilité générale ni une déclaration fiscale officielle.</li>
            </ul>
        </section>
    </div>
</x-app-layout>
