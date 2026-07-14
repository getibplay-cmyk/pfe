<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6"><div><p class="text-sm text-slate-500">Traçabilité</p><h1 class="text-3xl font-bold">Journal d’audit</h1></div>
        <div class="overflow-hidden rounded-xl bg-white shadow-sm"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Date</th><th class="p-4">Action</th><th class="p-4">Entité</th><th class="p-4">Acteur</th></tr></thead><tbody>@forelse($logs as $log)<tr class="border-t"><td class="p-4">{{ $log->created_at?->format('d/m/Y H:i') }}</td><td class="p-4 font-medium">{{ $log->action }}</td><td class="p-4">{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td><td class="p-4">{{ $log->user?->name ?? 'Système' }}</td></tr>@empty<tr><td class="p-8 text-slate-500" colspan="4">Aucune activité auditée.</td></tr>@endforelse</tbody></table></div>{{ $logs->links() }}
    </div>
</x-app-layout>
