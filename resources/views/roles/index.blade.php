<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header title="Rôles et permissions" eyebrow="Gouvernance des accès" description="Les rôles système sont protégés. Les rôles personnalisés restent propres à votre entreprise.">
            <x-slot:actions>
                @can('delegate', App\Models\Role::class)<a href="{{ route('roles.delegations') }}" class="rf-button-secondary">Délégations par agence</a>@endcan
                @can('create', App\Models\Role::class)<a href="{{ route('roles.create') }}" class="rf-button-primary">Créer un rôle</a>@endcan
            </x-slot:actions>
        </x-page-header>

        <x-section-card title="Matrice des rôles" description="Une permission absente ne peut pas être utilisée, même si un lien est visible par erreur.">
            <div class="grid gap-4 md:grid-cols-2">
                @forelse ($roles as $role)
                    <article class="rounded-xl border border-slate-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div><h2 class="font-semibold text-slate-950">{{ $role->displayName() }}</h2><p class="mt-1 text-xs text-slate-500">{{ $role->is_system ? 'Rôle système protégé' : 'Rôle personnalisé' }} · {{ $role->users_count }} utilisateur(s)</p></div>
                            <x-status-badge :value="$role->is_active ? 'active' : 'inactive'" />
                        </div>
                        <ul class="mt-4 flex flex-wrap gap-2" aria-label="Permissions du rôle">
                            @forelse ($role->permissions as $permission)<li class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">{{ $permission->name }}</li>@empty<li class="text-sm text-slate-500">Aucune permission.</li>@endforelse
                        </ul>
                        @can('update', $role)<a href="{{ route('roles.edit', $role) }}" class="mt-4 inline-flex text-sm font-semibold text-brand-700">Modifier ce rôle</a>@endcan
                    </article>
                @empty
                    <x-empty-state title="Aucun rôle" description="Aucun rôle n’est disponible dans votre entreprise." />
                @endforelse
            </div>
        </x-section-card>
    </div>
</x-app-layout>
