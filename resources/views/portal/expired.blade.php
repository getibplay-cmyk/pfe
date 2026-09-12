<x-portal-layout :title="__('Votre accès locataire est indisponible')">
    <x-section-card :title="__('Demandez un nouveau lien à votre agence')" :description="__('Votre lien ou votre session peut avoir expiré ou avoir été révoqué. Les anciens liens sont à usage unique.')">
        <p class="text-sm leading-6 text-slate-600">{{ __('Contactez votre agence de location par votre canal habituel pour recevoir un nouvel accès à vos dossiers.') }}</p>
        <a class="rf-button-secondary mt-4" href="{{ route('home') }}">{{ __('Revenir à l’accueil') }}</a>
    </x-section-card>
</x-portal-layout>
