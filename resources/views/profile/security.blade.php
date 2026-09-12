<x-app-layout>
    <div class="rf-page max-w-4xl">
        <x-page-header :title="__('Sécurité du compte')" :description="__('Protégez votre connexion et contrôlez vos appareils.')" />
        <x-section-card :title="__('Double authentification')">
            <x-input-error :messages="$errors->all()" class="mb-4" />
            @if ($user->mfa_confirmed_at)
                <p class="mb-4 text-emerald-800">{{ __('Double authentification activée.') }} {{ count($user->mfa_recovery_hashes ?? []) }} {{ __('codes de secours disponibles.') }}</p>
                @unless (config('security.mfa_require_admins') && ($user->is_platform_admin || $user->isTenantOwner()))
                    <form method="POST" action="{{ route('security.disable') }}" class="space-y-3">
                        @csrf @method('DELETE')
                        <x-input-label for="disable-code" :value="__('Code de vérification ou de secours')" />
                        <x-text-input id="disable-code" name="code" dir="ltr" autocomplete="one-time-code" maxlength="40" required />
                        <button class="rf-button-danger">{{ __('Désactiver la double authentification') }}</button>
                    </form>
                @endunless
            @elseif ($user->mfa_pending_secret && $user->mfa_pending_at?->gt(now()->subMinutes(10)))
                <p class="mb-3 text-sm">{{ __('Ajoutez ce compte dans votre application avec une clé de configuration, un code à 6 chiffres et une période de 30 secondes.') }}</p>
                <p class="mb-3 text-sm">{{ __('Compte') }} : <bdi>{{ $user->email }}</bdi></p>
                <code dir="ltr" class="block break-all rounded-lg bg-slate-100 p-4 select-all">{{ $user->mfa_pending_secret }}</code>
                <p class="my-3 text-sm">{{ __('Cette clé expire après 10 minutes. Conservez-la privée.') }}</p>
                <form method="POST" action="{{ route('security.enroll') }}" class="space-y-3">
                    @csrf
                    <x-input-label for="code" :value="__('Code à 6 chiffres')" />
                    <x-text-input id="code" name="code" inputmode="numeric" dir="ltr" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required />
                    <x-primary-button>{{ __('Activer et obtenir mes codes de secours') }}</x-primary-button>
                </form>
            @else
                <p class="mb-4 text-sm text-slate-600">{{ __('Une application d’authentification fournira un code en complément de votre mot de passe. Des codes de secours permettent de récupérer l’accès.') }}</p>
                <form method="POST" action="{{ route('security.prepare') }}">@csrf<x-primary-button>{{ __('Configurer la double authentification') }}</x-primary-button></form>
            @endif
        </x-section-card>
        <x-section-card :title="__('Sessions connectées')">
            @if (config('session.driver') !== 'database')
                <p class="text-sm">{{ __('La gestion des appareils nécessite les sessions en base de données.') }}</p>
            @else
                <ul class="divide-y divide-slate-200">
                    @forelse ($sessions as $session)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                            <div class="min-w-0"><p dir="ltr" class="break-all text-sm">{{ $session['device'] }}</p><p class="mt-1 text-xs text-slate-600"><bdi>{{ $session['ip'] }}</bdi> · {{ \Carbon\CarbonImmutable::createFromTimestamp($session['at'])->locale(app()->getLocale())->diffForHumans() }}</p></div>
                            @if ($session['current'])<span class="text-sm font-semibold">{{ __('Cet appareil') }}</span>
                            @else<form method="POST" action="{{ route('security.revoke') }}">@csrf @method('DELETE')<input type="hidden" name="session_key" value="{{ $session['key'] }}"><button class="rf-button-secondary">{{ __('Révoquer') }}</button></form>@endif
                        </li>
                    @empty<li class="py-3 text-sm">{{ __('Aucune autre session active.') }}</li>@endforelse
                </ul>
            @endif
        </x-section-card>
        <a class="rf-button-link" href="{{ route('profile.edit') }}">{{ __('Mon profil') }}</a>
    </div>
</x-app-layout>
