<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6">
        <x-page-header :title="$managedUser->exists ? __('Modifier l’utilisateur') : __('Nouvel utilisateur')" :eyebrow="__('Administration des accès')" :description="__('L’entreprise, l’agence et les rôles autorisés sont toujours revalidés côté serveur.')" />
        <x-form-errors />
        <form class="space-y-5" method="POST" action="{{ $managedUser->exists ? route('users.update', $managedUser) : route('users.store') }}">
            @csrf @if($managedUser->exists) @method('PUT') @endif
            <x-section-card :title="__('Compte utilisateur')">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div><x-input-label for="user-name" :value="__('Nom')" required /><input id="user-name" class="mt-1 w-full" name="name" value="{{ old('name', $managedUser->name) }}" aria-describedby="user-name-error" required><x-field-error id="user-name-error" :messages="$errors->get('name')" /></div>
                    <div><x-input-label for="user-email" :value="__('E-mail')" required /><input id="user-email" type="email" class="mt-1 w-full" name="email" value="{{ old('email', $managedUser->email) }}" aria-describedby="user-email-error" required><x-field-error id="user-email-error" :messages="$errors->get('email')" /></div>
                    <div><x-input-label for="user-role" :value="__('Rôle autorisé')" required /><select id="user-role" class="mt-1 w-full" name="role_id" aria-describedby="user-role-help user-role-error" required>@foreach($roles as $role)<option value="{{ $role->id }}" @selected(old('role_id', $managedUser->role_id) == $role->id)>{{ $role->displayName() }}</option>@endforeach</select><p id="user-role-help" class="mt-1 text-xs text-slate-500">{{ __('La liste est filtrée côté serveur selon vos délégations et votre plafond de permissions.') }}</p><x-field-error id="user-role-error" :messages="$errors->get('role_id')" /></div>
                    <div><x-input-label for="user-agency" :value="__('Agence')" /><select id="user-agency" class="mt-1 w-full" name="agency_id" aria-describedby="user-agency-help user-agency-error"><option value="">{{ __('Toutes les agences — administrateur uniquement') }}</option>@foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected(old('agency_id', $managedUser->agency_id) == $agency->id)>{{ $agency->name }}</option>@endforeach</select><p id="user-agency-help" class="mt-1 text-xs text-slate-500">{{ __('Un responsable d’agence reste limité à sa propre agence.') }}</p><x-field-error id="user-agency-error" :messages="$errors->get('agency_id')" /></div>
                </div>
                <label class="mt-5 flex items-center gap-3 text-sm font-medium"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked(old('is_active', $managedUser->is_active ?? true))> {{ __('Compte actif') }}</label>
                @unless($managedUser->exists)<p class="mt-4 text-sm text-slate-500">{{ __('Un mot de passe temporaire aléatoire sera affiché une seule fois après création.') }}</p>@endunless
            </x-section-card>
            <div class="flex justify-end gap-3"><a href="{{ route('users.index') }}" class="rf-button-secondary">{{ __('Annuler') }}</a><x-confirmation-button type="submit" variant="secondary" :message="__('Confirmer l’enregistrement de cet utilisateur et de son rôle ?')">{{ __('Enregistrer') }}</x-confirmation-button></div>
        </form>
        @if($managedUser->exists) @can('update', $managedUser)
            <x-section-card :title="__('Réinitialisation sécurisée')" :description="__('Les autres sessions seront révoquées et le nouveau mot de passe temporaire ne sera affiché qu’une fois.')">
                <form method="POST" action="{{ route('users.reset-password', $managedUser) }}">@csrf<x-confirmation-button :message="__('Réinitialiser le mot de passe et révoquer les sessions ?')">{{ __('Réinitialiser le mot de passe') }}</x-confirmation-button></form>
            </x-section-card>
        @endcan @endif
    </div>
</x-app-layout>
