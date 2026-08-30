<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Mon abonnement RentFleet" eyebrow="Compte SaaS" :description="'Consultation de l’offre, des paiements administratifs et des assistances accessibles pour '.$tenant->name.'.'" />

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
                @else<x-empty-state title="Aucun abonnement courant" description="Votre accès historique reste disponible. Contactez l’administration RentFleet pour toute question." />@endif
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

        @if($subscriptions->count() > 1)
            <x-section-card title="Historique des abonnements">
                <div class="divide-y">@foreach($subscriptions as $subscription)<div class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><span><strong>{{ $subscription->plan->name }}</strong><span class="block text-slate-500">Du {{ App\Support\Ui\UiLabel::dateTime($subscription->starts_at) }}{{ $subscription->ends_at ? ' au '.App\Support\Ui\UiLabel::dateTime($subscription->ends_at) : '' }}</span></span><span>{{ match($subscription->status->value) {'trialing' => 'Période d’essai', 'active' => 'Actif', 'past_due' => 'Échéance dépassée', 'suspended' => 'Suspendu', 'cancelled' => 'Annulé', 'expired' => 'Expiré', default => 'Inactif'} }}</span></div>@endforeach</div>
            </x-section-card>
        @endif
    </div>
</x-app-layout>
