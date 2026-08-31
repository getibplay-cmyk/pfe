<x-guest-layout>
    <x-auth-heading
        icon="key"
        eyebrow="Action sensible"
        title="Confirmer votre mot de passe"
        :description="'Cette vérification protège l’accès à une opération sensible de '.config('brand.name').'.'"
    />
    <form method="POST" action="{{ route('password.confirm') }}" class="mt-7 space-y-5" data-loading-form>
        @csrf
        <x-form-errors />
        <x-password-field id="password" name="password" label="Mot de passe actuel" :messages="$errors->get('password')" autocomplete="current-password" autofocus />
        <x-submit-button label="Confirmer et continuer" loading-label="Vérification en cours…" icon="lock" class="w-full" />
    </form>
</x-guest-layout>
