<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header :title="__('Rôles et permissions')" :eyebrow="__('Gouvernance des accès')" :description="__('Les rôles système sont protégés. Les rôles personnalisés restent propres à votre entreprise.')">
            <x-slot:actions>
                @can('delegate', App\Models\Role::class)<a href="{{ route('roles.delegations') }}" class="rf-button-secondary">{{ __('Délégations par agence') }}</a>@endcan
                @can('create', App\Models\Role::class)<a href="{{ route('roles.create') }}" class="rf-button-primary"><x-icon name="add" size="xs" />{{ __('Créer un rôle') }}</a>@endcan
            </x-slot:actions>
        </x-page-header>

        <x-section-card :title="__('Matrice des rôles')" :description="__('Une permission absente ne peut pas être utilisée, même si un lien est visible par erreur.')">
            <div class="grid gap-4 md:grid-cols-2">
                @forelse ($roles as $role)
                    <article class="rounded-xl border border-slate-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div><h2 class="font-semibold text-slate-950">{{ $role->displayName() }}</h2><p class="mt-1 text-xs text-slate-500">{{ $role->is_system ? __('Rôle système protégé') : __('Rôle personnalisé') }} · {{ App\Support\Ui\BusinessNumber::count($role->users_count, 'utilisateur') }}</p></div>
                            <x-status-badge :value="$role->is_active ? 'active' : 'inactive'" />
                        </div>
                        <ul class="mt-4 flex flex-wrap gap-2" aria-label="{{ __('Permissions du rôle') }}">
                            @forelse ($role->permissions as $permission)<li class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">{{ App\Support\Ui\UiLabel::permission($permission->slug) }}</li>@empty<li class="text-sm text-slate-500">{{ __('Aucune permission.') }}</li>@endforelse
                        </ul>
                        @can('update', $role)<div class="mt-4"><x-icon-button icon="edit" :label="__('Modifier le rôle ').$role->displayName()" :href="route('roles.edit', $role)" /></div>@endcan
                    </article>
                @empty
                    <x-empty-state :title="__('Aucun rôle')" :description="__('Aucun rôle n’est disponible dans votre entreprise.')" />
                @endforelse
            </div>
        </x-section-card>
    </div>
</x-app-layout>
