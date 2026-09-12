<x-guest-layout>
    <x-auth-heading
        icon="mail"
        :eyebrow="__('Sécurité du compte')"
        :title="__('Vérifier votre adresse e-mail')"
        :description="__('Utilisez le lien envoyé à votre adresse professionnelle. Vous pouvez demander un nouvel envoi si le message n’est pas arrivé.')"
    />
    @if (session('status') === 'verification-link-sent')<x-flash-message class="mt-5" :message="__('Un nouveau lien de vérification a été envoyé.')" />@endif
    @if (session('error'))<x-flash-message type="error" class="mt-5" :message="session('error')" />@endif
    <div class="mt-7 space-y-3">
        <form method="POST" action="{{ route('verification.send') }}" data-loading-form>
            @csrf
            <x-submit-button :label="__('Renvoyer le lien')" loading-label="{{ __('Envoi en cours…') }}" icon="mail" class="w-full" />
        </form>
        <form method="POST" action="{{ route('logout') }}" data-loading-form>
            @csrf
            <x-submit-button :label="__('Se déconnecter')" loading-label="{{ __('Déconnexion…') }}" variant="secondary" class="w-full" />
        </form>
    </div>
</x-guest-layout>
