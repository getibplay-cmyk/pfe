<x-app-layout>
    @php($activeFilterCount = collect(['status', 'severity'])->filter(fn (string $key): bool => request()->filled($key))->count())
    <div class="rf-page">
        <x-page-header title="Supervision de production" eyebrow="Administration de la plateforme" description="Contrôlez le scheduler, les files de traitements, les callbacks CMI, le rapprochement financier et l’espace de stockage.">
            <x-slot:actions>
                <form method="POST" action="{{ route('platform.operations.refresh') }}" data-loading-form>
                    @csrf
                    <x-submit-button label="Actualiser maintenant" loading-label="Contrôle en cours…" icon="refresh" />
                </form>
            </x-slot:actions>
        </x-page-header>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-stat-card label="Incidents ouverts" :value="App\Support\Ui\BusinessNumber::integer($counts['open'])" icon="warning" :tone="$counts['open'] > 0 ? 'danger' : 'success'" />
            <x-stat-card label="Incidents critiques" :value="App\Support\Ui\BusinessNumber::integer($counts['critical'])" icon="warning" :tone="$counts['critical'] > 0 ? 'danger' : 'success'" />
            <x-stat-card label="Incidents résolus" :value="App\Support\Ui\BusinessNumber::integer($counts['resolved'])" icon="success" tone="brand" />
        </div>

        <x-section-card title="État de la collecte" description="La commande planifiée actualise les contrôles chaque minute et n’enregistre ni secret, ni payload bancaire, ni donnée personnelle.">
            @if(! $monitoringFresh)
                <div role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-900">La collecte est inactive ou trop ancienne. Vérifiez immédiatement le scheduler avec <code>php artisan rentfleet:doctor --production</code>.</div>
            @endif
            <p class="text-sm text-slate-700">
                @if($lastCheckedAt)
                    Dernière actualisation : <strong>{{ App\Support\Ui\UiLabel::dateTime(Carbon\CarbonImmutable::parse($lastCheckedAt)) }}</strong>.
                @else
                    Aucun contrôle n’a encore été enregistré. Lancez l’actualisation ou vérifiez le scheduler.
                @endif
            </p>
        </x-section-card>

        <x-filter-panel title="Filtrer les incidents" :active-count="$activeFilterCount" :result-count="$incidents->total()">
            <form method="GET" class="rf-filter-grid" data-loading-form>
                <div><x-input-label for="operations-status" value="État" /><select id="operations-status" name="status" class="mt-1 w-full"><option value="">Tous</option><option value="open" @selected(request('status') === 'open')>Ouverts</option><option value="resolved" @selected(request('status') === 'resolved')>Résolus</option></select></div>
                <div><x-input-label for="operations-severity" value="Sévérité" /><select id="operations-severity" name="severity" class="mt-1 w-full"><option value="">Toutes</option><option value="critical" @selected(request('severity') === 'critical')>Critique</option><option value="warning" @selected(request('severity') === 'warning')>Avertissement</option></select></div>
                <div class="flex items-end gap-2"><x-submit-button label="Filtrer" loading-label="Filtrage…" />@if($activeFilterCount)<a href="{{ route('platform.operations.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />Effacer</a>@endif</div>
            </form>
        </x-filter-panel>

        <div class="space-y-4">
            @forelse($incidents as $incident)
                <article class="rounded-2xl border bg-white p-5 shadow-sm {{ $incident->status === 'open' ? ($incident->severity === 'critical' ? 'border-red-200' : 'border-amber-200') : 'border-emerald-200' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2"><h2 class="font-semibold text-slate-950">{{ $incident->title }}</h2><x-status-badge :value="$incident->status" /><x-status-badge :value="$incident->severity" /></div>
                            <p class="mt-2 text-sm leading-6 text-slate-700">{{ $incident->summary }}</p>
                            <p class="mt-2 text-xs text-slate-500">Code {{ $incident->code }} · {{ App\Support\Ui\BusinessNumber::integer($incident->occurrence_count) }} détection(s)</p>
                        </div>
                        <div class="text-right text-xs text-slate-500"><p>Première détection<br><strong class="text-slate-700">{{ App\Support\Ui\UiLabel::dateTime($incident->first_detected_at) }}</strong></p><p class="mt-2">Dernière détection<br><strong class="text-slate-700">{{ App\Support\Ui\UiLabel::dateTime($incident->last_detected_at) }}</strong></p></div>
                    </div>
                    @if($incident->events->isNotEmpty())
                        <details class="mt-4 border-t border-slate-100 pt-3"><summary class="cursor-pointer text-sm font-semibold text-belkhir-space-blue">Historique des changements d’état</summary><ol class="mt-3 space-y-2">@foreach($incident->events as $event)<li class="flex flex-wrap justify-between gap-2 text-sm"><span>{{ match($event->event_type) {'opened' => 'Incident ouvert', 'reopened' => 'Incident rouvert', default => 'Incident résolu'} }}</span><time class="text-slate-500">{{ App\Support\Ui\UiLabel::dateTime($event->observed_at) }}</time></li>@endforeach</ol></details>
                    @endif
                </article>
            @empty
                <x-empty-state title="Aucun incident opérationnel" description="Exécutez un premier contrôle pour établir l’état de référence." />
            @endforelse
        </div>

        {{ $incidents->links() }}
    </div>
</x-app-layout>
