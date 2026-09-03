<x-app-layout>
    <div class="rf-page">
        <x-page-header :title="'Mon abonnement '.config('brand.name')" eyebrow="Compte SaaS" :description="'Consultation de l’offre, des règlements et des assistances accessibles pour '.$tenant->name.'.'" />

        @php($subscriptionProgress = 1 + ($currentSubscription ? 1 : 0) + ($payments->contains(fn ($payment) => $payment->entry_type->value === 'payment') ? 1 : 0))
        <x-section-card title="Activation du service" description="Progression fondée sur trois contrôles réels : e-mail vérifié, abonnement attribué et règlement enregistré.">
            <x-progress-bar label="Activation de l’abonnement" :value="$subscriptionProgress" :max="3" :value-text="$subscriptionProgress.' étapes sur 3'" />
        </x-section-card>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Abonnement courant">
                @if($currentSubscription)
                    @php($subscriptionLabel = match($currentSubscription->status->value) {'trialing' => 'Période d’essai', 'active' => 'Actif', 'past_due' => 'Échéance dépassée', 'suspended' => 'Suspendu', default => 'Inactif'})
                    <x-metadata-list>
                        <x-metadata-item label="Plan">{{ $currentSubscription->plan->name }}</x-metadata-item>
                        <x-metadata-item label="État">{{ $subscriptionLabel }}</x-metadata-item>
                        <x-metadata-item label="Périodicité">{{ $currentSubscription->billing_interval->value === 'annual' ? 'Annuelle' : 'Mensuelle' }}</x-metadata-item>
                        <x-metadata-item label="Tarif">{{ App\Support\Ui\UiLabel::money($currentSubscription->price_amount, $currentSubscription->currency) }}</x-metadata-item>
                        <x-metadata-item label="Début">{{ App\Support\Ui\UiLabel::dateTime($currentSubscription->starts_at) }}</x-metadata-item>
                        <x-metadata-item label="Prochain renouvellement">{{ App\Support\Ui\UiLabel::dateTime($currentSubscription->next_renewal_at) }}</x-metadata-item>
                        <x-metadata-item label="Fin prévue">{{ App\Support\Ui\UiLabel::dateTime($currentSubscription->ends_at) }}</x-metadata-item>
                    </x-metadata-list>
                    <div class="mt-6 border-t border-slate-200 pt-5">
                        @if($cmiReadiness['ready'] && (float) $currentSubscription->price_amount > 0)
                            <form method="POST" action="{{ route('tenant-saas-checkout.store', $currentSubscription) }}" data-loading-form>
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                                <x-submit-button label="Payer par carte avec CMI" loading-label="Préparation du paiement…" icon="payment" class="w-full sm:w-auto" />
                            </form>
                            <p class="mt-2 text-xs leading-5 text-slate-500">Vous serez redirigé vers la page sécurisée de CMI. Aucune donnée de carte n’est saisie ici.</p>
                        @else
                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">{{ $cmiReadiness['message'] }} Contactez l’administration pour un règlement alternatif.</div>
                        @endif
                    </div>
                @else<x-empty-state title="Aucun abonnement courant" :description="'Votre accès historique reste disponible. Contactez l’administration '.config('brand.name').' pour toute question.'" />@endif
            </x-section-card>

            <x-section-card title="Assistances accessibles" description="Ces fonctions restent consultatives et soumises à vos permissions métier.">
                <ul class="divide-y">@forelse($enabledCapabilities as $capability)<li class="flex items-center gap-2 py-3 text-sm"><span class="h-2 w-2 rounded-full bg-emerald-600" aria-hidden="true"></span>{{ $capability }}</li>@empty<li><x-empty-state title="Aucune assistance disponible" /></li>@endforelse</ul>
            </x-section-card>
        </div>

        <x-section-card title="Historique des paiements SaaS" description="Ces écritures administratives sont distinctes des paiements de vos locations.">
            <x-responsive-table label="Paiements SaaS" class="shadow-none">
                <table class="rf-table"><thead><tr><th>Date</th><th>Type</th><th>Moyen</th><th class="text-right">Montant</th></tr></thead><tbody>
                    @forelse($payments as $payment)<tr><td>{{ App\Support\Ui\UiLabel::dateTime($payment->occurred_at) }}</td><td>{{ $payment->entry_type->value === 'reversal' ? 'Contrepassation' : 'Paiement enregistré' }}</td><td>{{ App\Support\Ui\UiLabel::get($payment->payment_method) }}</td><td class="text-right">{{ $payment->entry_type->value === 'reversal' ? '−' : '' }}{{ App\Support\Ui\UiLabel::money($payment->amount, $payment->currency) }}</td></tr>
                    @empty<tr><td colspan="4"><x-empty-state title="Aucun paiement SaaS enregistré" /></td></tr>@endforelse
                </tbody></table>
            </x-responsive-table>
        </x-section-card>

        @if($paymentAttempts->isNotEmpty())
            <x-section-card title="Tentatives de paiement CMI" description="Le statut confirmé provient exclusivement du callback signé de la passerelle.">
                <x-responsive-table label="Tentatives CMI" class="shadow-none"><table class="rf-table"><thead><tr><th>Date</th><th>Référence</th><th>État</th><th class="text-right">Montant</th></tr></thead><tbody>@foreach($paymentAttempts as $attempt)<tr><td>{{ App\Support\Ui\UiLabel::dateTime($attempt->created_at) }}</td><td>{{ $attempt->merchant_order_id }}</td><td><x-status-badge :value="$attempt->status" /></td><td class="text-right">{{ App\Support\Ui\UiLabel::money($attempt->amount, $attempt->currency) }}</td></tr>@endforeach</tbody></table></x-responsive-table>
            </x-section-card>
        @endif

        @if($subscriptions->count() > 1)
            <x-section-card title="Historique des abonnements">
                <div class="divide-y">@foreach($subscriptions as $subscription)<div class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><span><strong>{{ $subscription->plan->name }}</strong><span class="block text-slate-500">Du {{ App\Support\Ui\UiLabel::dateTime($subscription->starts_at) }}{{ $subscription->ends_at ? ' au '.App\Support\Ui\UiLabel::dateTime($subscription->ends_at) : '' }}</span></span><span>{{ match($subscription->status->value) {'trialing' => 'Période d’essai', 'active' => 'Actif', 'past_due' => 'Échéance dépassée', 'suspended' => 'Suspendu', 'cancelled' => 'Annulé', 'expired' => 'Expiré', default => 'Inactif'} }}</span></div>@endforeach</div>
            </x-section-card>
        @endif
    </div>
</x-app-layout>
