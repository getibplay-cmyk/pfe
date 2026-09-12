<x-guest-layout>
    <x-auth-heading
        icon="key"
        :eyebrow="__('Action sensible')"
        :title="__('Confirmer votre mot de passe')"
        :description="__('Cette vérification protège l’accès à une opération sensible de ').config('brand.name').'.'"
    />
    <form method="POST" action="{{ route('password.confirm') }}" class="mt-7 space-y-5" data-loading-form>
        @csrf
        <x-form-errors />
        <x-password-field id="password" name="password" :label="__('Mot de passe actuel')" :messages="$errors->get('password')" autocomplete="current-password" autofocus />
        <x-submit-button :label="__('Confirmer et continuer')" loading-label="{{ __('Vérification en cours…') }}" icon="lock" class="w-full" />
    </form>
</x-guest-layout>
