<x-guest-layout>
    <x-auth-heading
        icon="key"
        :eyebrow="__('Sécurité du compte')"
        :title="__('Choisissez votre mot de passe')"
        :description="__('Le mot de passe temporaire doit être remplacé avant d’accéder aux fonctions ').config('brand.name').'.'"
    />

    <div class="mt-5 rounded-xl border border-sanad-pilot-border bg-sanad-pilot-canvas px-4 py-3 text-xs leading-5 text-sanad-pilot-muted">
        {{ __('Utilisez une phrase de passe unique d’au moins 15 caractères.') }}
    </div>

    <form method="POST" action="{{ route('password.change-required.update') }}" class="mt-7 space-y-5" data-loading-form>
        @csrf
        @method('PUT')
        <x-form-errors />
        <x-password-field id="current_password" name="current_password" :label="__('Mot de passe temporaire')" :messages="$errors->get('current_password')" autocomplete="current-password" autofocus />
        <x-password-field id="password" name="password" :label="__('Nouveau mot de passe')" :messages="$errors->get('password')" autocomplete="new-password" />
        <x-password-field id="password_confirmation" name="password_confirmation" :label="__('Confirmation du mot de passe')" :messages="$errors->get('password_confirmation')" autocomplete="new-password" />
        <x-submit-button :label="__('Enregistrer et continuer')" loading-label="{{ __('Enregistrement en cours…') }}" class="w-full" />
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-3" data-loading-form>
        @csrf
        <x-submit-button :label="__('Se déconnecter')" loading-label="{{ __('Déconnexion…') }}" variant="secondary" class="w-full" />
    </form>
</x-guest-layout>
