<x-app-layout>
    <div class="rf-page max-w-4xl">
        <x-page-header :title="__('Paiement sécurisé CMI')" :eyebrow="__('Abonnement SaaS')" :description="__('Vous allez quitter temporairement BELKHIR SPACE pour saisir vos informations sur la page hébergée de CMI.')" />

        <x-section-card :title="__('Redirection vers CMI')" :description="__('Ne fermez pas cette page pendant la redirection.')">
            <x-progress-bar :label="__('Parcours de paiement')" :value="2" :max="3" value-text="Étape 2 sur 3 : ouverture de la page CMI" tone="orange" />
            <div class="mt-6 grid gap-4 rounded-xl border border-slate-200 bg-slate-50 p-5 sm:grid-cols-2">
                <div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Offre') }}</p><p class="mt-1 font-bold text-slate-950">{{ $attempt->subscription->plan->name }}</p></div>
                <div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Montant transmis') }}</p><p class="mt-1 font-bold text-slate-950">{{ App\Support\Ui\UiLabel::money($attempt->amount, $attempt->currency) }}</p></div>
            </div>
            <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-950"><strong>{{ __('Vos données bancaires restent chez CMI.') }}</strong> {{ config('brand.name') }} {{ __('ne reçoit ni numéro de carte, ni date d’expiration, ni cryptogramme.') }}</div>

            <form method="POST" action="{{ $endpoint }}" class="mt-7" data-loading-form data-cmi-checkout-form>
                @foreach($fields as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <x-submit-button :label="__('Continuer vers le paiement CMI')" loading-label="{{ __('Ouverture de la page sécurisée…') }}" icon="lock" class="w-full sm:w-auto" />
            </form>
            <noscript><p class="mt-3 text-sm text-slate-600">{{ __('JavaScript est désactivé : utilisez le bouton ci-dessus pour continuer.') }}</p></noscript>
        </x-section-card>
    </div>
</x-app-layout>
