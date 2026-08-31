<x-guest-layout>
    <x-auth-heading
        icon="mail"
        eyebrow="Récupération du compte"
        title="Mot de passe oublié"
        description="Saisissez votre adresse e-mail professionnelle. Si un compte est associé, vous recevrez un lien de réinitialisation."
    />
    <x-auth-session-status class="mt-5" :status="session('status')" />
    <form method="POST" action="{{ route('password.email') }}" class="mt-7 space-y-5" data-loading-form>
        @csrf
        <x-form-errors />
        <x-auth-email-field :value="old('email')" :messages="$errors->get('email')" autofocus />
        <x-submit-button label="Envoyer le lien de réinitialisation" loading-label="Envoi en cours…" icon="mail" class="w-full" />
    </form>
    <a class="rf-button-link mt-4 w-full justify-center" href="{{ route('login') }}">
        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6" /></svg>
        Retour à la connexion
    </a>
</x-guest-layout>
