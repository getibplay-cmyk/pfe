@php($activeFilters = collect([request('q'), request('role_id'), request('status')])->filter(fn ($value) => filled($value))->count())

<x-app-layout>
    <div class="rf-page max-w-6xl">
        <x-page-header
            title="Utilisateurs"
            eyebrow="Accès"
            description="Comptes limités à votre entreprise et, pour les responsables d’agence, à leur agence."
        >
            <x-slot:actions>
                @can('create', App\Models\User::class)
                    <x-link-button variant="primary" href="{{ route('users.create') }}">
                        <x-icon name="add" size="sm" />
                        Nouvel utilisateur
                    </x-link-button>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <x-filter-panel id="user-filters" title="Rechercher un utilisateur" :active-count="$activeFilters" :result-count="$users->total()">
            <form method="GET" action="{{ route('users.index') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_minmax(11rem,0.8fr)_minmax(11rem,0.8fr)_auto]" data-loading-form>
                <div>
                    <x-input-label for="user-search" value="Nom ou e-mail" />
                    <div class="relative mt-1">
                        <span class="pointer-events-none absolute inset-y-0 start-0 flex w-11 items-center justify-center text-belkhir-space-muted" aria-hidden="true"><x-icon name="search" size="sm" /></span>
                        <input id="user-search" name="q" value="{{ request('q') }}" class="w-full ps-11" autocomplete="off">
                    </div>
                </div>
                <div>
                    <x-input-label for="user-role" value="Rôle" />
                    <select id="user-role" name="role_id" class="mt-1 w-full">
                        <option value="">Tous les rôles</option>
                        @foreach ($filterRoles as $role)
                            <option value="{{ $role->id }}" @selected(request('role_id') == $role->id)>{{ $role->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="user-status" value="Statut" />
                    <select id="user-status" name="status" class="mt-1 w-full">
                        <option value="">Tous les statuts</option>
                        <option value="active" @selected(request('status') === 'active')>Actifs</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Inactifs</option>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <x-submit-button label="Appliquer" loading-label="Filtrage…" />
                    @if ($activeFilters > 0)
                        <x-link-button href="{{ route('users.index') }}" class="px-3" aria-label="Réinitialiser les filtres">
                            <x-icon name="reset" size="sm" />
                            <span class="hidden sm:inline">Réinitialiser</span>
                        </x-link-button>
                    @endif
                </div>
            </form>
        </x-filter-panel>

        <x-result-count :paginator="$users" />

        <x-responsive-table label="Utilisateurs">
            <table>
                <thead><tr><th>Utilisateur</th><th>Rôle</th><th>Agence</th><th>Statut</th><th>Dernière activité</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($users as $managedUser)
                        <tr>
                            <td><div class="flex items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-belkhir-space-blue" aria-hidden="true"><x-icon name="users" size="sm" /></span>
                                <div class="min-w-0"><p class="truncate font-semibold text-belkhir-space-text">{{ $managedUser->name }}</p><p class="truncate text-xs text-belkhir-space-muted">{{ $managedUser->email }}</p></div>
                            </div></td>
                            <td>{{ $managedUser->role?->displayName() ?? 'Aucun rôle' }}</td>
                            <td>{{ $managedUser->agency?->name ?? 'Toutes les agences' }}</td>
                            <td>
                                <x-status-badge :value="$managedUser->is_active ? 'active' : 'inactive'" />
                                @if ($managedUser->must_change_password)<p class="mt-1.5 text-xs font-medium text-belkhir-space-warning">Mot de passe à changer</p>@endif
                            </td>
                            <td>{{ App\Support\Ui\UiLabel::dateTime($managedUser->last_login_at) }}</td>
                            <td><div class="flex justify-end">
                                @can('update', $managedUser)
                                    <x-icon-button icon="edit" :label="'Modifier l’utilisateur '.$managedUser->name" :href="route('users.edit', $managedUser)" />
                                @endcan
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-4"><x-empty-state title="Aucun utilisateur trouvé" description="Aucun utilisateur ne correspond aux filtres sélectionnés." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-responsive-table>

        {{ $users->links() }}
    </div>
</x-app-layout>
