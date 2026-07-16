<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header title="Journal d’audit" eyebrow="Traçabilité" description="Les valeurs sensibles sont retirées avant enregistrement et ne sont jamais affichées ici." />
        <form method="GET" class="flex gap-3 rounded-xl bg-white p-4"><label for="audit-search" class="sr-only">Rechercher une action</label><input id="audit-search" name="q" value="{{ request('q') }}" placeholder="Type d’action" class="min-w-0 flex-1"><button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Rechercher</button></form>
        <x-result-count :paginator="$logs" />
        <div class="overflow-x-auto rounded-xl bg-white shadow-sm"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Date</th><th class="p-4">Action</th><th class="p-4">Entité</th><th class="p-4">Acteur</th></tr></thead><tbody>@forelse($logs as $log)<tr class="border-t"><td class="p-4">{{ App\Support\Ui\UiLabel::dateTime($log->created_at) }}</td><td class="p-4 font-medium">{{ App\Support\Ui\UiLabel::action($log->action) }}</td><td class="p-4">{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td><td class="p-4">{{ $log->user?->name ?? 'Système' }}</td></tr>@empty<tr><td class="p-8 text-slate-500" colspan="4">Aucune activité auditée ne correspond à la recherche.</td></tr>@endforelse</tbody></table></div>{{ $logs->links() }}
    </div>
</x-app-layout>
