<x-guest-layout>
    <x-auth-heading
        icon="key"
        :eyebrow="__('Sécurité du compte')"
        :title="__('Réinitialiser le mot de passe')"
        :description="__('Choisissez un mot de passe unique d’au moins 12 caractères, avec majuscules, minuscules et chiffres.')"
    />
    <form method="POST" action="{{ route('password.store') }}" class="mt-7 space-y-5" data-loading-form>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-form-errors />
        <x-auth-email-field :value="old('email', $request->email)" :messages="$errors->get('email')" autofocus />
        <x-password-field id="password" name="password" :label="__('Nouveau mot de passe')" :messages="$errors->get('password')" autocomplete="new-password" />
        <x-password-field id="password_confirmation" name="password_confirmation" :label="__('Confirmation du mot de passe')" :messages="$errors->get('password_confirmation')" autocomplete="new-password" />
        <x-submit-button :label="__('Enregistrer le nouveau mot de passe')" loading-label="{{ __('Enregistrement en cours…') }}" class="w-full" />
    </form>
</x-guest-layout>
