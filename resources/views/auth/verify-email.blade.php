<x-guest-layout>
    <x-auth-heading
        icon="mail"
        eyebrow="Sécurité du compte"
        title="Vérifier votre adresse e-mail"
        description="Utilisez le lien envoyé à votre adresse professionnelle. Vous pouvez demander un nouvel envoi si le message n’est pas arrivé."
    />
    @if (session('status') === 'verification-link-sent')<x-flash-message class="mt-5" message="Un nouveau lien de vérification a été envoyé." />@endif
    @if (session('error'))<x-flash-message type="error" class="mt-5" :message="session('error')" />@endif
    <div class="mt-7 space-y-3">
        <form method="POST" action="{{ route('verification.send') }}" data-loading-form>
            @csrf
            <x-submit-button label="Renvoyer le lien" loading-label="Envoi en cours…" icon="mail" class="w-full" />
        </form>
        <form method="POST" action="{{ route('logout') }}" data-loading-form>
            @csrf
            <x-submit-button label="Se déconnecter" loading-label="Déconnexion…" variant="secondary" class="w-full" />
        </form>
    </div>
</x-guest-layout>
