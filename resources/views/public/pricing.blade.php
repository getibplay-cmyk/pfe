<x-public-layout :title="__('Tarifs')">
    <section class="bg-belkhir-space-ink px-4 py-14 text-center text-white sm:px-6 sm:py-20">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-300">{{ __('Tarifs publics') }}</p>
        <h1 class="mx-auto mt-4 max-w-3xl text-4xl font-bold tracking-tight sm:text-5xl">{{ __('Des offres lisibles, en dirhams.') }}</h1>
        <p class="mx-auto mt-5 max-w-2xl text-base leading-7 text-slate-300">{{ __('Les plans actifs sont publiés directement depuis l’administration de la plateforme. L’ouverture d’un compte reste validée par l’équipe') }} {{ config('brand.name') }}.</p>
    </section>
    <section class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
        @if($plans->isEmpty())
            <div class="rf-panel p-8 text-center"><h2 class="text-xl font-bold text-slate-950">{{ __('Tarifs en cours de publication') }}</h2><p class="mt-3 text-slate-600">{{ __('Contactez notre équipe pour obtenir une proposition adaptée à votre organisation.') }}</p></div>
        @else
            <div class="grid gap-6 lg:grid-cols-3">
                @foreach($plans as $plan)
                    <article class="rf-panel flex min-w-0 flex-col overflow-hidden {{ $loop->first ? 'ring-2 ring-belkhir-space-blue' : '' }}">
                        @if($loop->first)<p class="bg-belkhir-space-blue px-5 py-2 text-center text-xs font-bold uppercase tracking-wider text-white">{{ __('Première offre disponible') }}</p>@endif
                        <div class="flex flex-1 flex-col p-6 sm:p-7">
                            <h2 class="text-2xl font-bold text-slate-950">{{ $plan->name }}</h2>
                            @if($plan->description)<p class="mt-3 min-h-12 text-sm leading-6 text-slate-600">{{ $plan->description }}</p>@endif
                            <p class="mt-6 text-4xl font-bold tracking-tight text-belkhir-space-blue">{{ App\Support\Ui\UiLabel::money($plan->price_amount, $plan->currency) }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Facturation') }} {{ $plan->billing_interval->value === 'annual' ? 'annuelle' : 'mensuelle' }}</p>
                            @php($rights = $plan->entitlement_summary)
                            <dl class="mt-6 grid grid-cols-2 gap-2 rounded-xl bg-slate-50 p-4 text-sm">
                                @foreach([['Agences', $rights['max_agencies']], ['Utilisateurs', $rights['max_users']], ['Véhicules', $rights['max_vehicles']], ['Analyses/mois', $rights['monthly_intelligence_runs']]] as [$label, $limit])
                                    <div><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $limit === null ? __('Sans limite') : App\Support\Ui\BusinessNumber::integer($limit) }}</dd></div>
                                @endforeach
                            </dl>
                            <ul class="mt-7 flex-1 space-y-3 text-sm text-slate-700">@forelse($plan->features ?? [] as $feature)<li class="flex gap-3"><span class="mt-0.5 text-emerald-600"><x-icon name="success" size="xs" /></span><span>{{ $feature }}</span></li>@empty<li class="text-slate-500">{{ __('Fonctions précisées lors de l’activation.') }}</li>@endforelse</ul>
                            <p class="mt-4 text-xs text-slate-500">{{ count($rights['intelligence_capabilities']) }} {{ __('assistance(s) intelligente(s) incluse(s), sous réserve de disponibilité opérationnelle.') }}</p>
                            <a href="{{ route('subscription.public', ['plan' => $plan->code]) }}" class="rf-button-primary mt-8 w-full">{{ __('Demander cette offre') }} <x-icon name="next" size="xs" /></a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
        <p class="mx-auto mt-8 max-w-3xl text-center text-sm leading-6 text-slate-500">{{ __('Le paiement par carte est effectué sur l’interface hébergée de CMI lorsque la passerelle marchande est activée.') }} {{ config('brand.name') }} {{ __('ne collecte ni ne conserve les données de carte.') }}</p>
    </section>
</x-public-layout>
