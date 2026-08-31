<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <x-page-header :title="$role->exists ? 'Modifier le rôle personnalisé' : 'Créer un rôle personnalisé'" eyebrow="Gouvernance des accès" description="Choisissez uniquement les capacités réellement nécessaires. Les permissions de plateforme et de gouvernance ne sont jamais délégables." />
        <x-form-errors />
        <form method="POST" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}" class="space-y-6">
            @csrf @if($role->exists) @method('PUT') @endif
            <x-section-card title="Identité du rôle">
                <x-input-label for="role-name" value="Nom du rôle" required /><x-text-input id="role-name" name="name" class="mt-1 block w-full" :value="old('name', $role->name)" :invalid="$errors->has('name')" aria-describedby="role-name-error" required /><x-field-error id="role-name-error" :messages="$errors->get('name')" />
                @if($role->exists)<p class="mt-2 text-xs text-slate-500">L’identifiant technique est géré par {{ config('brand.name') }} et ne peut pas être modifié.</p>@endif
            </x-section-card>
            <x-section-card title="Permissions" description="Chaque case correspond à une capacité serveur explicite. Les intitulés techniques ne sont pas modifiables.">
                @php($selected = collect(old('permission_ids', $role->permissions->modelKeys()))->map(fn($id) => (int) $id))
                <div class="space-y-5">
                    @foreach($permissions as $group => $items)
                        <fieldset><legend class="mb-2 text-sm font-semibold text-slate-900">{{ App\Support\Ui\UiLabel::permissionGroup($group) }}</legend>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach($items as $permission)
                                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm"><input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" class="mt-1 rounded border-slate-300" @checked($selected->contains($permission->id))><span><span class="font-medium text-slate-900">{{ App\Support\Ui\UiLabel::permission($permission->slug) }}</span><span class="mt-0.5 block text-xs text-slate-500">{{ App\Support\Ui\UiLabel::permissionDescription($permission->slug) }}</span><span class="mt-0.5 block text-xs text-slate-500">{{ App\Support\Ui\UiLabel::permissionScope($permission->slug) }}</span><span class="mt-0.5 block text-xs font-medium text-slate-600">{{ App\Support\Ui\UiLabel::permissionCriticality($permission->slug) }}</span></span></label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div><x-field-error :messages="$errors->get('permission_ids')" />
            </x-section-card>
            @if($role->exists)
                <x-section-card title="État du rôle" description="La désactivation retire immédiatement les capacités. Si le rôle est attribué, un remplacement contrôlé est obligatoire.">
                    <input type="hidden" name="is_active" value="0"><label class="flex items-center gap-3 text-sm font-medium"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked(old('is_active', $role->is_active))> Rôle actif</label>
                    @if($replacementImpact['user_count'] > 0)
                        <div id="replacement-impact" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                            <p class="font-semibold">{{ App\Support\Ui\BusinessNumber::count($replacementImpact['user_count'], 'utilisateur') }} seront réaffectés.</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5">@foreach($replacementImpact['agencies'] as $agency)<li>{{ $agency['name'] }} : {{ App\Support\Ui\BusinessNumber::count($agency['user_count'], 'utilisateur') }}</li>@endforeach</ul>
                        </div>
                    @endif
                    <div class="mt-4"><x-input-label for="replacement-role" value="Rôle de remplacement en cas de désactivation" /><select id="replacement-role" name="replacement_role_id" aria-describedby="{{ $replacementImpact['user_count'] > 0 ? 'replacement-help replacement-impact' : 'replacement-help' }}" class="mt-1 w-full rounded-lg border-slate-300"><option value="">Aucun remplacement</option>@foreach($replacementRoles as $replacement)<option value="{{ $replacement->id }}" @selected(old('replacement_role_id') == $replacement->id)>{{ $replacement->displayName() }}</option>@endforeach</select><p id="replacement-help" class="mt-1 text-xs text-slate-500">Seuls les rôles actifs, délégués dans toutes les agences concernées et sans permission supplémentaire sont proposés.</p><x-field-error :messages="$errors->get('replacement_role_id')" /></div>
                    @if($replacementImpact['user_count'] > 0)
                        <label class="mt-4 flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm"><input type="checkbox" name="confirm_replacement" value="1" class="mt-1 rounded border-slate-300" @checked(old('confirm_replacement'))><span>Je confirme la réaffectation des {{ App\Support\Ui\BusinessNumber::count($replacementImpact['user_count'], 'utilisateur') }} indiqués ci-dessus.</span></label>
                        <x-field-error :messages="$errors->get('confirm_replacement')" />
                    @endif
                </x-section-card>
            @endif
            <div class="flex flex-wrap justify-end gap-3"><a href="{{ route('roles.index') }}" class="rf-button-secondary">Annuler</a><x-confirmation-button type="submit" variant="secondary" message="Confirmer l’enregistrement de ce rôle et de ses permissions ?">Enregistrer</x-confirmation-button></div>
        </form>
    </div>
</x-app-layout>
