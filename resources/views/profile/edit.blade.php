<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page-header :title="__('Mon profil')" :eyebrow="__('Compte utilisateur')" :description="__('Modifiez vos informations personnelles et sécurisez votre accès. Les droits et rattachements sont administrés séparément.')" />
        <div class="grid gap-6 lg:grid-cols-3">
            <x-section-card :title="__('Informations personnelles')" :description="__('Votre nom et votre adresse e-mail sont les seules informations modifiables ici.')" class="lg:col-span-2">
                @include('profile.partials.update-profile-information-form')
            </x-section-card>
            <x-section-card :title="__('Rattachement')" as="aside">
                <x-metadata-list class="sm:grid-cols-1">
                    <x-metadata-item :label="__('Organisation')">{{ $user->tenant?->name ?? __('Administration de la plateforme') }}</x-metadata-item>
                    <x-metadata-item :label="__('Agence')">{{ $user->agency?->name ?? ($user->is_platform_admin ? '—' : __('Toutes les agences autorisées')) }}</x-metadata-item>
                    <x-metadata-item :label="__('Rôle')">{{ $user->is_platform_admin ? __('Administrateur plateforme') : App\Support\Ui\UiLabel::get($user->role?->slug) }}</x-metadata-item>
                    <x-metadata-item :label="__('État du compte')"><x-status-badge :value="$user->is_active ? 'active' : 'inactive'" /></x-metadata-item>
                    <x-metadata-item :label="__('Dernière connexion')">{{ App\Support\Ui\UiLabel::dateTime($user->last_login_at) }}</x-metadata-item>
                </x-metadata-list>
                <p class="mt-5 rounded-lg bg-slate-50 p-3 text-xs leading-5 text-slate-600">{{ __('Le rôle, l’organisation, l’agence et l’état du compte ne sont jamais modifiables depuis le profil personnel.') }}</p>
            </x-section-card>
        </div>
        <x-section-card :title="__('Sécurité du compte')" :description="__('Le changement du mot de passe ferme vos autres sessions actives.')">
            <a href="{{ route('security.index') }}" class="rf-button-secondary mb-5">{{ __('Double authentification et appareils') }}</a>
            @include('profile.partials.update-password-form')
        </x-section-card>
    </div>
</x-app-layout>
