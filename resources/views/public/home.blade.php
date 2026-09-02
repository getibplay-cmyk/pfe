<x-public-layout :title="'Accueil'">
    <section class="relative isolate overflow-hidden bg-belkhir-space-ink text-white">
        <div class="absolute inset-0 -z-10 opacity-70" aria-hidden="true">
            <span class="absolute -right-24 -top-28 h-96 w-96 rounded-full border-[4rem] border-belkhir-space-blue/20"></span>
            <span class="absolute -bottom-24 left-[12%] h-72 w-72 rounded-full border-[3rem] border-belkhir-space-orange/15"></span>
        </div>
        <div class="mx-auto grid max-w-7xl gap-12 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:px-8 lg:py-28">
            <div>
                <p class="border-s-4 border-belkhir-space-orange ps-3 text-xs font-bold uppercase tracking-[0.2em] text-brand-300">SaaS de gestion automobile</p>
                <h1 class="mt-5 max-w-3xl text-4xl font-bold leading-tight tracking-tight sm:text-5xl lg:text-6xl">Une vue claire de chaque véhicule, contrat et décision.</h1>
                <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-300">{{ config('brand.name') }} réunit les opérations, la finance et les assistances intelligentes de votre entreprise dans un espace multi-agences sécurisé.</p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('pricing') }}" class="rf-button-primary min-h-12 px-6">Voir les tarifs <x-icon name="next" size="xs" /></a>
                    <a href="{{ route('subscription.public') }}" class="rf-button min-h-12 border-white/20 bg-white/5 px-6 text-white hover:bg-white/10">Comment s’abonner</a>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2" aria-label="Points forts">
                @foreach ([
                    ['icon' => 'vehicle', 'title' => 'Parc maîtrisé', 'text' => 'Disponibilité, maintenance et assurance au même endroit.'],
                    ['icon' => 'file', 'title' => 'Cycle locatif suivi', 'text' => 'De la réservation au retour et à la facture.'],
                    ['icon' => 'chart', 'title' => 'Décisions éclairées', 'text' => 'Prévisions consultatives avec validation humaine.'],
                    ['icon' => 'lock', 'title' => 'Accès contrôlés', 'text' => 'Isolation par entreprise, rôles et journal d’audit.'],
                ] as $benefit)
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-5 backdrop-blur-sm">
                        <span class="grid h-11 w-11 place-items-center rounded-xl bg-belkhir-space-blue/20 text-brand-300"><x-icon :name="$benefit['icon']" /></span>
                        <h2 class="mt-4 font-bold">{{ $benefit['title'] }}</h2><p class="mt-2 text-sm leading-6 text-slate-300">{{ $benefit['text'] }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20">
        <div class="max-w-2xl"><p class="text-xs font-bold uppercase tracking-[0.18em] text-belkhir-space-orange">Un espace, plusieurs métiers</p><h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Conçu pour suivre l’activité réelle.</h2></div>
        <div class="mt-10 grid gap-5 md:grid-cols-3">
            @foreach ([
                ['01', 'Exploiter', 'Réservations, contrats, inspections, clients et disponibilité du parc.'],
                ['02', 'Contrôler', 'Factures, encaissements, cautions, dépenses et indicateurs consolidés.'],
                ['03', 'Anticiper', 'Prévisions de demande et assistances visuelles, toujours sous contrôle humain.'],
            ] as $item)
                <article class="rf-panel rf-interactive-card p-6"><span class="text-sm font-bold text-belkhir-space-blue">{{ $item[0] }}</span><h3 class="mt-3 text-xl font-bold text-slate-950">{{ $item[1] }}</h3><p class="mt-3 text-sm leading-6 text-slate-600">{{ $item[2] }}</p></article>
            @endforeach
        </div>
    </section>

    @if($plans->isNotEmpty())
        <section class="border-y border-slate-200 bg-white">
            <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.18em] text-belkhir-space-orange">Offres disponibles</p><h2 class="mt-2 text-3xl font-bold text-slate-950">Choisissez une base adaptée.</h2></div><a href="{{ route('pricing') }}" class="rf-button-link">Comparer toutes les offres <x-icon name="next" size="xs" /></a></div>
                <div class="mt-8 grid gap-5 md:grid-cols-3">@foreach($plans as $plan)<article class="rounded-2xl border border-slate-200 p-6"><h3 class="text-lg font-bold text-slate-950">{{ $plan->name }}</h3><p class="mt-3 text-3xl font-bold text-belkhir-space-blue">{{ App\Support\Ui\UiLabel::money($plan->price_amount, $plan->currency) }}</p><p class="mt-1 text-sm text-slate-500">par {{ $plan->billing_interval->value === 'annual' ? 'an' : 'mois' }}</p></article>@endforeach</div>
            </div>
        </section>
    @endif

    <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20">
        <div class="overflow-hidden rounded-3xl bg-belkhir-space-blue p-7 text-white shadow-xl sm:p-10 lg:flex lg:items-center lg:justify-between lg:gap-10">
            <div><h2 class="text-2xl font-bold sm:text-3xl">Votre entreprise est déjà cliente ?</h2><p class="mt-3 max-w-2xl text-brand-100">Connectez-vous pour consulter votre abonnement et, lorsque CMI est activé, régler votre échéance sur la page bancaire sécurisée.</p></div>
            <a href="{{ route('login') }}" class="rf-button mt-6 min-h-12 shrink-0 border-white bg-white px-6 text-belkhir-space-blue hover:bg-brand-50 lg:mt-0"><x-icon name="login" size="xs" />Accéder à mon espace</a>
        </div>
    </section>
</x-public-layout>
