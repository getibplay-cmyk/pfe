<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Tableau de bord" eyebrow="Vue d’ensemble" description="Priorités opérationnelles de votre périmètre actuel, sans donnée sensible." />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($kpis as $label => $value)<x-stat-card :label="$label" :value="$value" />@endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            @if ($maintenanceSummary !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2"><div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Pilotage maintenance</h2><a href="{{ route('maintenance.index') }}" class="text-sm font-medium text-indigo-700">Voir le module</a></div><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">@foreach($maintenanceSummary as $label => $value)<x-stat-card :label="$label" :value="$value" />@endforeach</div></section>
            @endif
            @if ($insuranceSummary !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2"><div class="flex items-center justify-between gap-3"><div><h2 class="font-semibold">Pilotage assurance</h2><p class="text-sm text-slate-500">Alertes administratives uniquement, sans décision juridique automatique.</p></div><a href="{{ route('insurance.index') }}" class="text-sm font-medium text-indigo-700">Voir le module</a></div><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($insuranceSummary as $label => $value)<x-stat-card :label="$label" :value="$value" />@endforeach</div></section>
            @endif
            @if ($upcomingReservations !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Réservations à venir</h2><a href="{{ route('reservations.index') }}" class="text-sm font-medium text-indigo-700">Voir tout</a></div>
                    <div class="mt-4 space-y-3">
                        @forelse ($upcomingReservations as $reservation)
                            <a href="{{ route('reservations.show', $reservation) }}" class="block rounded-lg border p-3 hover:bg-slate-50"><div class="flex justify-between gap-3"><span class="font-medium">{{ $reservation->reservation_number }}</span><x-status-badge :value="$reservation->status" /></div><p class="mt-1 text-sm text-slate-600">{{ $reservation->customer->displayName() }} · {{ App\Support\Ui\UiLabel::dateTime($reservation->starts_at) }}</p></a>
                        @empty <x-empty-state title="Aucune réservation à venir" description="Les réservations confirmées des 30 prochains jours apparaîtront ici." /> @endforelse
                    </div>
                </section>
            @endif

            @if ($expectedReturns !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Retours attendus ou en retard</h2><a href="{{ route('contracts.index') }}" class="text-sm font-medium text-indigo-700">Voir tout</a></div>
                    <div class="mt-4 space-y-3">
                        @forelse ($expectedReturns as $contract)
                            <a href="{{ route('contracts.show', $contract) }}" class="flex items-center justify-between gap-3 rounded-lg border p-3 hover:bg-slate-50"><span><strong>{{ $contract->contract_number }}</strong><br><span class="text-sm text-slate-600">{{ $contract->vehicle->registration_number }} · {{ App\Support\Ui\UiLabel::dateTime($contract->expected_return_at) }}</span></span>@if($contract->expected_return_at->isPast())<span class="text-xs font-semibold text-red-700">En retard</span>@else<x-status-badge :value="$contract->status" />@endif</a>
                        @empty <x-empty-state title="Aucun retour imminent" /> @endforelse
                    </div>
                </section>
            @endif

            @if ($unavailableVehicles !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Véhicules indisponibles</h2><a href="{{ route('vehicles.index', ['status' => 'maintenance']) }}" class="text-sm font-medium text-indigo-700">Voir la flotte</a></div>
                    <div class="mt-4 divide-y">
                        @forelse ($unavailableVehicles as $vehicle)<a href="{{ route('vehicles.show', $vehicle) }}" class="flex items-center justify-between gap-3 py-3"><span>{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }}</span><x-status-badge :value="$vehicle->operational_status" /></a>@empty <x-empty-state title="Toute la flotte est opérationnelle" /> @endforelse
                    </div>
                </section>
            @endif

            @if ($unpaidInvoices !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Factures impayées</h2><a href="{{ route('finance.index') }}" class="text-sm font-medium text-indigo-700">Voir la finance</a></div>
                    <div class="mt-4 divide-y">
                        @forelse ($unpaidInvoices as $invoice)<a href="{{ route('finance.invoices.show', $invoice) }}" class="flex items-center justify-between gap-3 py-3"><span><strong>{{ $invoice->invoice_number }}</strong><br><span class="text-sm text-slate-500">Échéance {{ App\Support\Ui\UiLabel::date($invoice->due_at) }}</span></span><span class="font-semibold">{{ App\Support\Ui\UiLabel::money($invoice->balance_due, $invoice->currency) }}</span></a>@empty <x-empty-state title="Aucune facture impayée" /> @endforelse
                    </div>
                </section>
            @endif

            @if ($upcomingMaintenance !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Maintenances à surveiller</h2><a href="{{ route('maintenance.index') }}" class="text-sm font-medium text-indigo-700">Voir tout</a></div>
                    <div class="mt-4 divide-y">@forelse ($upcomingMaintenance as $maintenance)<a href="{{ route('maintenance.show', $maintenance) }}" class="flex items-center justify-between gap-3 py-3"><span>{{ $maintenance->maintenance_number }} · {{ $maintenance->vehicle->registration_number }}</span><x-status-badge :value="$maintenance->status" /></a>@empty <x-empty-state title="Aucune maintenance proche" /> @endforelse</div>
                </section>
            @endif

            @if ($openClaims !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Sinistres ouverts</h2><a href="{{ route('insurance.index') }}" class="text-sm font-medium text-indigo-700">Voir l’assurance</a></div>
                    <div class="mt-4 divide-y">@forelse ($openClaims as $claim)<a href="{{ route('insurance.claims.show', $claim) }}" class="flex items-center justify-between gap-3 py-3"><span>{{ $claim->claim_number }}</span><x-status-badge :value="$claim->status" /></a>@empty <x-empty-state title="Aucun sinistre ouvert" /> @endforelse</div>
                </section>
            @endif
            @if ($expiringPolicies !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-semibold">Polices proches de l’échéance</h2><div class="mt-4 divide-y">@forelse($expiringPolicies as $policy)<a href="{{ route('insurance.policies.show',$policy) }}" class="flex items-center justify-between gap-3 py-3 text-sm"><span>{{ $policy->vehicle->registration_number }} · {{ $policy->company->name }}</span><span>{{ App\Support\Ui\UiLabel::date($policy->ends_at) }}</span></a>@empty<x-empty-state title="Aucune échéance proche" />@endforelse</div></section>
            @endif
            @if ($uninsuredVehicles !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-semibold">Véhicules sans police active</h2><div class="mt-4 divide-y">@forelse($uninsuredVehicles as $vehicle)<a href="{{ route('vehicles.show',$vehicle) }}" class="block py-3 text-sm">{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }}</a>@empty<x-empty-state title="Tous les véhicules actifs sont couverts" />@endforelse</div></section>
            @endif

            @if ($expiringDocuments !== null || $expiringLicences !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-semibold">Échéances documentaires</h2>
                    <div class="mt-4 space-y-4 text-sm">
                        @if ($expiringLicences !== null) @forelse ($expiringLicences as $driver)<div class="rounded-lg border p-3"><strong>Permis à renouveler</strong><p class="text-slate-600">{{ $driver->first_name }} {{ $driver->last_name }} · {{ App\Support\Ui\UiLabel::date($driver->licence_expires_at) }}</p></div>@empty @endforelse @endif
                        @if ($expiringDocuments !== null) @forelse ($expiringDocuments as $document)<a href="{{ route('documents.show', $document) }}" class="block rounded-lg border p-3"><strong>{{ $document->title }}</strong><p class="text-slate-600">Échéance de conservation · {{ App\Support\Ui\UiLabel::date($document->retention_until) }}</p></a>@empty @endforelse @endif
                        @if (($expiringLicences?->isEmpty() ?? true) && ($expiringDocuments?->isEmpty() ?? true))<x-empty-state title="Aucune échéance documentaire proche" />@endif
                    </div>
                </section>
            @endif

            @if ($recentActivity !== null)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Activité récente</h2><a href="{{ route('audit-logs.index') }}" class="text-sm font-medium text-indigo-700">Voir le journal</a></div>
                    <ol class="mt-4 space-y-3 text-sm">@forelse ($recentActivity as $activity)<li class="border-l-2 border-indigo-200 pl-3"><strong>{{ App\Support\Ui\UiLabel::action($activity->action) }}</strong><p class="text-slate-500">{{ $activity->user?->name ?? 'Système' }} · {{ App\Support\Ui\UiLabel::dateTime($activity->created_at) }}</p></li>@empty <x-empty-state title="Aucune activité récente" /> @endforelse</ol>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
