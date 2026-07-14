<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex items-center justify-between"><div><p class="text-sm text-slate-500">Accès</p><h1 class="text-3xl font-bold">Utilisateurs</h1></div>@can('create', App\Models\User::class)<a href="{{ route('users.create') }}" class="rounded-lg bg-slate-950 px-4 py-2 text-sm text-white">Nouvel utilisateur</a>@endcan</div>
        <div class="overflow-hidden rounded-xl bg-white shadow-sm"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Utilisateur</th><th class="p-4">Rôle</th><th class="p-4">Agence</th><th class="p-4">Statut</th><th class="p-4"></th></tr></thead><tbody>@forelse($users as $managedUser)<tr class="border-t"><td class="p-4"><p class="font-medium">{{ $managedUser->name }}</p><p class="text-slate-500">{{ $managedUser->email }}</p></td><td class="p-4">{{ $managedUser->role?->name }}</td><td class="p-4">{{ $managedUser->agency?->name ?? 'Toutes' }}</td><td class="p-4">{{ $managedUser->is_active ? 'Actif' : 'Inactif' }}</td><td class="p-4 text-right">@can('update', $managedUser)<a class="text-blue-700" href="{{ route('users.edit', $managedUser) }}">Modifier</a>@endcan</td></tr>@empty<tr><td class="p-8 text-slate-500" colspan="5">Aucun utilisateur accessible.</td></tr>@endforelse</tbody></table></div>
        {{ $users->links() }}
    </div>
</x-app-layout>
