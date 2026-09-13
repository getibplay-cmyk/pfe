<x-app-layout>
    <div class="rf-page max-w-5xl">
        <x-page-header :title="__('Sécurité du compte')" :eyebrow="__('Mon espace personnel')" :description="__('Protégez votre connexion et contrôlez vos appareils.')">
            <x-slot:actions><a class="rf-button-secondary" href="{{ route('profile.edit') }}"><x-icon name="users" size="xs" />{{ __('Mon profil') }}</a></x-slot:actions>
        </x-page-header>
        <div class="grid gap-3 sm:grid-cols-3" aria-label="{{ __('État de votre protection') }}">
            <a href="#double-authentification" class="rf-security-summary hover:border-brand-300">
                <span class="rf-security-icon"><x-icon name="shield" /></span>
                <div class="min-w-0"><p class="text-xs font-medium text-slate-600">{{ __('Double authentification') }}</p><p @class(['mt-1 font-semibold', 'text-emerald-800' => $user->mfa_confirmed_at, 'text-amber-800' => !$user->mfa_confirmed_at])>{{ $user->mfa_confirmed_at ? __('Activée') : __('À configurer') }}</p></div>
            </a>
            <div class="rf-security-summary">
                <span class="rf-security-icon"><x-icon name="mail" /></span>
                <div class="min-w-0"><p class="text-xs font-medium text-slate-600">{{ __('Adresse e-mail') }}</p><p @class(['mt-1 font-semibold', 'text-emerald-800' => $user->hasVerifiedEmail(), 'text-amber-800' => !$user->hasVerifiedEmail()])>{{ $user->hasVerifiedEmail() ? __('Vérifiée') : __('À vérifier') }}</p></div>
            </div>
            <a href="#appareils" class="rf-security-summary hover:border-brand-300">
                <span class="rf-security-icon"><x-icon name="device" /></span>
                <div class="min-w-0"><p class="text-xs font-medium text-slate-600">{{ __('Appareils récents') }}</p><p class="mt-1 font-semibold text-slate-900">{{ config('session.driver') === 'database' ? __(':count affichés', ['count' => $sessions->count()]) : __('Non disponible') }}</p></div>
            </a>
        </div>
        <x-form-errors />
        <x-section-card id="double-authentification" :title="__('Double authentification')" :description="__('Un code temporaire protège votre compte en complément du mot de passe.')">
            @if ($user->mfa_confirmed_at)
                <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">
                    <x-icon name="shield" />
                    <div><p class="font-semibold">{{ __('Double authentification activée.') }}</p><p class="mt-1 text-sm">{{ count($user->mfa_recovery_hashes ?? []) }} {{ __('codes de secours disponibles.') }}</p></div>
                </div>
                @unless (config('security.mfa_require_admins') && ($user->is_platform_admin || $user->isTenantOwner()))
                    <details class="mt-5 rounded-xl border border-slate-200 p-4" @if($errors->has('code')) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ __('Désactiver la double authentification') }}</summary>
                        <form method="POST" action="{{ route('security.disable') }}" class="mt-4 space-y-3" x-belkhir-space-confirm data-confirm-title="{{ __('Désactiver la double authentification') }}" data-confirm-resource="{{ __('Sécurité du compte') }}" data-confirm-label="{{ __('Désactiver la double authentification') }}" data-confirm-consequence="{{ __('Les prochaines connexions ne demanderont plus de code temporaire.') }}" data-loading-form>
                            @csrf @method('DELETE')
                            <x-input-label for="disable-code" :value="__('Code de vérification ou de secours')" required />
                            <x-text-input id="disable-code" name="code" dir="ltr" autocomplete="one-time-code" maxlength="40" required :invalid="$errors->has('code')" aria-describedby="disable-code-error" />
                            <x-field-error id="disable-code-error" :messages="$errors->get('code')" />
                            <x-danger-button data-loading-submit>{{ __('Désactiver la double authentification') }}</x-danger-button>
                        </form>
                    </details>
                @endunless
            @elseif ($user->mfa_pending_secret && $user->mfa_pending_at?->gt(now()->subMinutes(10)))
                <ol class="mb-5 list-decimal space-y-2 ps-5 text-sm leading-6 text-slate-700">
                    <li>{{ __('Ajoutez ce compte dans votre application avec une clé de configuration, un code à 6 chiffres et une période de 30 secondes.') }}</li>
                    <li>{{ __('Saisissez le code affiché dans votre application pour terminer.') }}</li>
                </ol>
                <p class="mb-3 text-sm">{{ __('Compte') }} : <bdi>{{ $user->email }}</bdi></p>
                <code dir="ltr" class="block break-all rounded-xl border border-slate-200 bg-slate-50 p-4 select-all">{{ $user->mfa_pending_secret }}</code>
                <p class="my-3 text-sm text-slate-600">{{ __('Cette clé expire après 10 minutes. Conservez-la privée.') }}</p>
                <form method="POST" action="{{ route('security.enroll') }}" class="mt-5 space-y-3" data-loading-form>
                    @csrf
                    <x-input-label for="code" :value="__('Code à 6 chiffres')" required />
                    <x-text-input id="code" name="code" class="max-w-xs font-mono text-xl tracking-widest" inputmode="numeric" dir="ltr" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required :invalid="$errors->has('code')" aria-describedby="enroll-code-error" />
                    <x-field-error id="enroll-code-error" :messages="$errors->get('code')" />
                    <div><x-primary-button>{{ __('Activer et obtenir mes codes de secours') }}</x-primary-button></div>
                </form>
            @else
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="max-w-xl text-sm leading-6 text-slate-600">{{ __('Une application d’authentification fournira un code en complément de votre mot de passe. Des codes de secours permettent de récupérer l’accès.') }}</p>
                    <form method="POST" action="{{ route('security.prepare') }}" class="shrink-0" data-loading-form>@csrf<x-primary-button>{{ __('Configurer la double authentification') }}</x-primary-button></form>
                </div>
            @endif
        </x-section-card>
        <x-section-card id="appareils" :title="__('Sessions connectées')" :description="__('Consultez les 50 sessions actives les plus récentes. Les noms des appareils sont indicatifs.')">
            @if (config('session.driver') !== 'database')
                <p class="text-sm">{{ __('La gestion des appareils nécessite les sessions en base de données.') }}</p>
            @else
                <div class="mb-5 flex flex-col gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="max-w-xl text-sm leading-6 text-slate-600">{{ __('Vous ne reconnaissez pas un appareil ? Déconnectez-le, puis changez votre mot de passe.') }}</p>
                    <form method="POST" action="{{ route('security.revoke-others') }}" class="shrink-0" x-belkhir-space-confirm data-confirm-title="{{ __('Déconnecter les autres appareils') }}" data-confirm-resource="{{ __('Sessions connectées') }}" data-confirm-label="{{ __('Déconnecter les autres appareils') }}" data-confirm-consequence="{{ __('Les autres appareils devront se reconnecter. Votre session actuelle restera ouverte.') }}" data-loading-form>
                        @csrf @method('DELETE')<x-secondary-button type="submit" data-loading-submit>{{ __('Déconnecter les autres appareils') }}</x-secondary-button>
                    </form>
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($sessions as $session)
                        <li class="flex flex-wrap items-start gap-3 py-4">
                            <span class="rf-security-icon"><x-icon name="device" /></span>
                            <div class="min-w-0 flex-1 basis-40">
                                <p class="font-semibold text-slate-900"><bdi>{{ $session['browser'] }}</bdi> <span class="font-normal text-slate-500">· <bdi>{{ $session['platform'] }}</bdi></span></p>
                                <p class="mt-1 text-xs text-slate-600"><bdi>{{ $session['ip'] }}</bdi> · {{ \Carbon\CarbonImmutable::createFromTimestamp($session['at'])->locale(app()->getLocale())->diffForHumans() }}</p>
                                <details class="mt-2 text-xs text-slate-500"><summary class="cursor-pointer">{{ __('Détails de l’appareil') }}</summary><p dir="ltr" class="mt-2 break-all leading-5">{{ $session['device'] }}</p></details>
                            </div>
                            @if ($session['current'])<span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800">{{ __('Cet appareil') }}</span>
                            @else
                                <form method="POST" action="{{ route('security.revoke') }}" x-belkhir-space-confirm data-confirm-title="{{ __('Révoquer cette session') }}" data-confirm-label="{{ __('Révoquer') }}" data-confirm-resource="{{ $session['browser'] }} · {{ $session['platform'] }}" data-confirm-consequence="{{ __('Cet appareil devra se reconnecter pour accéder au compte.') }}" data-loading-form>
                                    @csrf @method('DELETE')<input type="hidden" name="session_key" value="{{ $session['key'] }}"><x-secondary-button type="submit" data-loading-submit>{{ __('Révoquer') }}</x-secondary-button>
                                </form>
                            @endif
                        </li>
                    @empty<li class="py-3 text-sm text-slate-600">{{ __('Aucune autre session active.') }}</li>@endforelse
                </ul>
            @endif
        </x-section-card>
    </div>
</x-app-layout>
