<x-public-layout :title="'Résultat du paiement'">
    <meta name="robots" content="noindex,nofollow">
    <section class="mx-auto max-w-3xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
        <div class="rf-panel overflow-hidden">
            <div class="h-1 {{ $attempt->status->value === 'paid' ? 'bg-emerald-600' : ($attempt->status->value === 'pending' ? 'bg-amber-500' : 'bg-red-600') }}"></div>
            <div class="p-6 sm:p-9">
                @if($attempt->status->value === 'paid')
                    <span class="grid h-12 w-12 place-items-center rounded-full bg-emerald-100 text-emerald-700"><x-icon name="success" /></span>
                    <h1 class="mt-5 text-3xl font-bold text-slate-950">Paiement confirmé</h1>
                    <p class="mt-3 leading-7 text-slate-600">Le callback signé de CMI a été vérifié et l’écriture de paiement a été enregistrée.</p>
                    <div class="mt-6"><x-progress-bar label="Parcours de paiement" :value="3" :max="3" value-text="Étape 3 sur 3 : paiement confirmé" /></div>
                @elseif($attempt->status->value === 'pending')
                    <span class="grid h-12 w-12 place-items-center rounded-full bg-amber-100 text-amber-700"><x-icon name="refresh" /></span>
                    <h1 class="mt-5 text-3xl font-bold text-slate-950">Confirmation en cours</h1>
                    <p class="mt-3 leading-7 text-slate-600">Le retour du navigateur ne suffit pas à confirmer un paiement. Nous attendons encore la notification signée de CMI.</p>
                    <div class="mt-6"><x-progress-bar label="Parcours de paiement" :value="2" :max="3" value-text="Étape 2 sur 3 : confirmation CMI attendue" tone="orange" /></div>
                @else
                    <span class="grid h-12 w-12 place-items-center rounded-full bg-red-100 text-red-700"><x-icon name="error" /></span>
                    <h1 class="mt-5 text-3xl font-bold text-slate-950">Paiement non confirmé</h1>
                    <p class="mt-3 leading-7 text-slate-600">Aucune écriture de paiement n’a été créée. Vous pourrez relancer une tentative depuis votre espace.</p>
                    <div class="mt-6"><x-progress-bar label="Parcours de paiement" :value="2" :max="3" value-text="Paiement interrompu" tone="orange" /></div>
                @endif

                <dl class="mt-7 grid gap-4 rounded-xl bg-slate-50 p-5 sm:grid-cols-2"><div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Offre</dt><dd class="mt-1 font-semibold text-slate-950">{{ $attempt->subscription->plan->name }}</dd></div><div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Montant</dt><dd class="mt-1 font-semibold text-slate-950">{{ App\Support\Ui\UiLabel::money($attempt->amount, $attempt->currency) }}</dd></div></dl>
                <div class="mt-7 flex flex-col gap-3 sm:flex-row"><a href="{{ route('login') }}" class="rf-button-primary">Accéder à mon espace</a><a href="{{ route('subscription.public') }}" class="rf-button-secondary">Informations sur l’abonnement</a></div>
            </div>
        </div>
    </section>
</x-public-layout>
