<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header title="Journal d’audit" eyebrow="Traçabilité" description="Les valeurs sensibles sont retirées avant enregistrement et ne sont jamais affichées ici." />
        <x-filter-panel title="Rechercher une activité"><form method="GET" class="flex flex-col gap-3 sm:flex-row"><label for="audit-search" class="sr-only">Rechercher une action</label><input id="audit-search" name="q" value="{{ request('q') }}" placeholder="Action auditée" class="min-w-0 flex-1"><x-primary-button>Rechercher</x-primary-button></form></x-filter-panel>
        <x-result-count :paginator="$logs" />
        <x-responsive-table label="Journal d’audit"><table><thead><tr><th>Date</th><th>Action</th><th>Élément</th><th>Acteur</th></tr></thead><tbody>
            @forelse($logs as $log)<tr><td>{{ App\Support\Ui\UiLabel::dateTime($log->created_at) }}</td><td class="font-medium">{{ App\Support\Ui\UiLabel::action($log->action) }}</td><td>{{ App\Support\Ui\UiLabel::entity($log->auditable_type) }} #{{ $log->auditable_id }}</td><td>{{ $log->user?->name ?? 'Système' }}</td></tr>
            @empty<tr><td class="p-8 text-slate-500" colspan="4">Aucune activité auditée ne correspond à la recherche.</td></tr>@endforelse
        </tbody></table></x-responsive-table>
        {{ $logs->links() }}
    </div>
</x-app-layout>
