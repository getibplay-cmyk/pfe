<x-app-layout>
    @php($activeFilterCount = collect(['q', 'tenant_id', 'date_from', 'date_to'])->filter(fn ($key) => request()->filled($key))->count())
    <div class="rf-page">
        <x-page-header title="Journal global" eyebrow="Administration de la plateforme" description="Traçabilité consolidée des actions de la plateforme et de toutes les entreprises clientes. Les valeurs sensibles sont filtrées avant stockage." />
        <x-filter-panel title="Filtrer les événements" :active-count="$activeFilterCount" :result-count="$logs->total()">
            <form method="GET" class="grid gap-4 md:grid-cols-2 xl:grid-cols-5" data-loading-form>
                <div class="xl:col-span-2"><x-input-label for="platform-audit-q" value="Action ou corrélation" /><input id="platform-audit-q" name="q" value="{{ request('q') }}" maxlength="100" class="mt-1 w-full"></div>
                <div><x-input-label for="platform-audit-tenant" value="Entreprise" /><select id="platform-audit-tenant" name="tenant_id" class="mt-1 w-full"><option value="">Toutes</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected((string) request('tenant_id') === (string) $tenant->id)>{{ $tenant->name }}</option>@endforeach</select></div>
                <div><x-input-label for="platform-audit-from" value="Du" /><input id="platform-audit-from" type="date" name="date_from" value="{{ request('date_from') }}" class="mt-1 w-full"></div>
                <div><x-input-label for="platform-audit-to" value="Au" /><input id="platform-audit-to" type="date" name="date_to" value="{{ request('date_to') }}" class="mt-1 w-full"></div>
                <div class="flex gap-2 xl:col-span-5"><x-submit-button label="Filtrer" loading-label="Filtrage…" />@if($activeFilterCount)<a href="{{ route('platform.audit-logs.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />Réinitialiser</a>@endif</div>
            </form>
        </x-filter-panel>
        <x-responsive-table label="Journal global de la plateforme"><table><thead><tr><th>Date</th><th>Entreprise</th><th>Action</th><th>Élément</th><th>Acteur</th><th>Corrélation</th></tr></thead><tbody>
            @forelse($logs as $log)<tr><td>{{ App\Support\Ui\UiLabel::dateTime($log->created_at) }}</td><td>{{ $log->tenant?->name ?? 'Plateforme' }}</td><td class="font-medium">{{ App\Support\Ui\UiLabel::action($log->action) }}</td><td>{{ App\Support\Ui\UiLabel::entity($log->auditable_type) }} #{{ $log->auditable_id }}</td><td>{{ $log->user?->name ?? 'Système / passerelle' }}</td><td><code class="text-xs text-slate-600">{{ $log->correlation_id }}</code></td></tr>
            @empty<tr><td colspan="6"><x-empty-state title="Aucun événement correspondant" /></td></tr>@endforelse
        </tbody></table><x-slot:footer>{{ $logs->links() }}</x-slot:footer></x-responsive-table>
    </div>
</x-app-layout>
