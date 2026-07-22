<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header title="Utilisateurs" eyebrow="Accès" description="Comptes limités à votre entreprise et, pour les responsables d’agence, à leur agence.">
            <x-slot:actions>@can('create', App\Models\User::class)<a href="{{ route('users.create') }}" class="rf-button-primary">Nouvel utilisateur</a>@endcan</x-slot:actions>
        </x-page-header>
        <x-filter-panel title="Rechercher et filtrer">
            <form method="GET" class="grid gap-3 md:grid-cols-4">
                <div><x-input-label for="user-search" value="Nom ou e-mail" /><input id="user-search" name="q" value="{{ request('q') }}" class="mt-1 w-full"></div>
                <div><x-input-label for="user-role" value="Rôle" /><select id="user-role" name="role_id" class="mt-1 w-full"><option value="">Tous les rôles</option>@foreach($filterRoles as $role)<option value="{{ $role->id }}" @selected(request('role_id') == $role->id)>{{ $role->displayName() }}</option>@endforeach</select></div>
                <div><x-input-label for="user-status" value="Statut" /><select id="user-status" name="status" class="mt-1 w-full"><option value="">Tous les statuts</option><option value="active" @selected(request('status') === 'active')>Actifs</option><option value="inactive" @selected(request('status') === 'inactive')>Inactifs</option></select></div>
                <div class="flex items-end gap-2"><x-primary-button class="flex-1">Filtrer</x-primary-button>@if(request()->hasAny(['q', 'role_id', 'status']))<a href="{{ route('users.index') }}" class="rf-button-secondary">Effacer</a>@endif</div>
            </form>
        </x-filter-panel>
        <x-result-count :paginator="$users" />
        <x-responsive-table label="Utilisateurs"><table><thead><tr><th>Utilisateur</th><th>Rôle</th><th>Agence</th><th>Statut</th><th>Dernière activité</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
            @forelse($users as $managedUser)<tr><td><p class="font-medium">{{ $managedUser->name }}</p><p class="text-slate-500">{{ $managedUser->email }}</p></td><td>{{ $managedUser->role?->displayName() ?? 'Aucun rôle' }}</td><td>{{ $managedUser->agency?->name ?? 'Toutes les agences' }}</td><td><x-status-badge :value="$managedUser->is_active ? 'active' : 'inactive'" />@if($managedUser->must_change_password)<br><span class="mt-1 inline-block text-xs text-amber-700">Mot de passe à changer</span>@endif</td><td>{{ App\Support\Ui\UiLabel::dateTime($managedUser->last_login_at) }}</td><td class="text-right">@can('update', $managedUser)<a class="rf-button-link" href="{{ route('users.edit', $managedUser) }}">Modifier</a>@endcan</td></tr>
            @empty<tr><td class="p-8 text-slate-500" colspan="6">Aucun utilisateur ne correspond aux filtres.</td></tr>@endforelse
        </tbody></table></x-responsive-table>
        {{ $users->links() }}
    </div>
</x-app-layout>
