<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <x-page-header :title="$role->exists ? 'Modifier le rôle personnalisé' : 'Créer un rôle personnalisé'" eyebrow="Gouvernance des accès" description="Choisissez uniquement les capacités réellement nécessaires. Les permissions de plateforme et de gouvernance ne sont jamais délégables." />
        <x-form-errors />
        <form method="POST" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}" class="space-y-6">
            @csrf @if($role->exists) @method('PUT') @endif
            <x-section-card title="Identité du rôle">
                <x-input-label for="role-name" value="Nom du rôle" /><x-text-input id="role-name" name="name" class="mt-1 block w-full" :value="old('name', $role->name)" required /><x-field-error :messages="$errors->get('name')" />
                @if($role->exists)<p class="mt-2 text-xs text-slate-500">L’identifiant technique est géré par RentFleet et ne peut pas être modifié.</p>@endif
            </x-section-card>
            <x-section-card title="Permissions" description="Chaque case correspond à une capacité serveur explicite. Les intitulés techniques ne sont pas modifiables.">
                @php($selected = collect(old('permission_ids', $role->permissions->modelKeys()))->map(fn($id) => (int) $id))
                <div class="space-y-5">
                    @foreach($permissions as $group => $items)
                        <fieldset><legend class="mb-2 text-sm font-semibold text-slate-900">{{ App\Support\Ui\UiLabel::permissionGroup($group) }}</legend>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach($items as $permission)
                                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm"><input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" class="mt-1 rounded border-slate-300" @checked($selected->contains($permission->id))><span><span class="font-medium text-slate-900">{{ $permission->name }}</span><span class="mt-0.5 block text-xs text-slate-500">{{ App\Support\Ui\UiLabel::permissionRisk($permission->slug) }}</span></span></label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div><x-field-error :messages="$errors->get('permission_ids')" />
            </x-section-card>
            @if($role->exists)
                <x-section-card title="État du rôle" description="La désactivation retire immédiatement les capacités. Si le rôle est attribué, un remplacement contrôlé est obligatoire.">
                    <input type="hidden" name="is_active" value="0"><label class="flex items-center gap-3 text-sm font-medium"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked(old('is_active', $role->is_active))> Rôle actif</label>
                    <div class="mt-4"><x-input-label for="replacement-role" value="Rôle de remplacement en cas de désactivation" /><select id="replacement-role" name="replacement_role_id" class="mt-1 w-full rounded-lg border-slate-300"><option value="">Aucun remplacement</option>@foreach($replacementRoles as $replacement)<option value="{{ $replacement->id }}" @selected(old('replacement_role_id') == $replacement->id)>{{ $replacement->displayName() }}</option>@endforeach</select><x-field-error :messages="$errors->get('replacement_role_id')" /></div>
                </x-section-card>
            @endif
            <div class="flex flex-wrap justify-end gap-3"><a href="{{ route('roles.index') }}" class="rf-button-secondary">Annuler</a><x-confirmation-button type="submit" variant="secondary" message="Confirmer l’enregistrement de ce rôle et de ses permissions ?">Enregistrer</x-confirmation-button></div>
        </form>
    </div>
</x-app-layout>
