<x-public-layout :title="'Abonnement'">
    <section class="bg-belkhir-space-ink px-4 py-14 text-white sm:px-6 sm:py-20">
        <div class="mx-auto max-w-7xl"><p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-300">Abonnement professionnel</p><h1 class="mt-4 max-w-3xl text-4xl font-bold tracking-tight sm:text-5xl">Une activation accompagnée, puis un paiement sécurisé.</h1><p class="mt-5 max-w-2xl text-base leading-7 text-slate-300">Aucune inscription anonyme : chaque entreprise, son administrateur et ses fonctions sont configurés avant l’ouverture de l’accès.</p></div>
    </section>
    <section class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
        <ol class="grid gap-5 md:grid-cols-3" aria-label="Étapes d’abonnement">
            @foreach ([
                ['1', 'Choix et validation', 'Sélectionnez une offre ; l’administrateur confirme le périmètre de votre entreprise.'],
                ['2', 'Création sécurisée', 'Le propriétaire reçoit un mot de passe temporaire et vérifie son adresse e-mail.'],
                ['3', 'Règlement CMI', 'Depuis son espace, il rejoint la page CMI, règle en MAD puis retrouve le statut confirmé.'],
            ] as $step)
                <li class="rf-panel p-6"><span class="grid h-10 w-10 place-items-center rounded-full bg-belkhir-space-blue text-sm font-bold text-white">{{ $step[0] }}</span><h2 class="mt-5 text-xl font-bold text-slate-950">{{ $step[1] }}</h2><p class="mt-3 text-sm leading-6 text-slate-600">{{ $step[2] }}</p></li>
            @endforeach
        </ol>
        <div class="mt-10 grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
            <article class="rf-panel p-6 sm:p-8"><h2 class="text-2xl font-bold text-slate-950">Déjà client</h2><p class="mt-3 text-sm leading-6 text-slate-600">Votre administrateur d’entreprise peut consulter le plan, les échéances, les paiements et les fonctions activées depuis son espace SaaS.</p><a href="{{ route('login') }}" class="rf-button-primary mt-6"><x-icon name="login" size="xs" />Se connecter</a></article>
            <article class="rounded-2xl border border-amber-200 bg-amber-50 p-6 sm:p-8"><h2 class="text-xl font-bold text-amber-950">Nouvelle entreprise</h2><p class="mt-3 text-sm leading-6 text-amber-900">La création publique de comptes est volontairement désactivée. Écrivez à l’équipe commerciale en indiquant l’offre souhaitée{{ request('plan') ? ' : '.request('plan') : '' }}.</p><a href="mailto:{{ config('brand.sales_email') }}" class="rf-button mt-6 border-amber-300 bg-white text-amber-950 hover:bg-amber-100"><x-icon name="mail" size="xs" />Contacter {{ config('brand.name') }}</a></article>
        </div>
        <div class="mt-10 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm leading-6 text-emerald-950"><strong>Paiement protégé :</strong> le numéro de carte, sa date d’expiration et son cryptogramme sont saisis uniquement chez CMI. Le serveur {{ config('brand.name') }} conserve une référence de transaction, le montant et le résultat signé nécessaires au suivi comptable.</div>
    </section>
</x-public-layout>
