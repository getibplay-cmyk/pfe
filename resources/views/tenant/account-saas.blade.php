<x-app-layout>
    <div class="rf-page">
        <x-page-header :title="'Mon abonnement '.config('brand.name')" eyebrow="Compte SaaS" :description="'Consultation de l’offre, des règlements et des assistances accessibles pour '.$tenant->name.'.'" />
        <x-form-errors />

        @if($pendingChange)
            <x-section-card title="Changement en attente de règlement" :description="$pendingChange->plan->name">
                <p class="text-sm">Demande valable jusqu’au {{ App\Support\Ui\UiLabel::dateTime($pendingChange->change_expires_at) }}. La période facturée commence à la demande ; l’activation exige le règlement. Aucun prorata ni remboursement automatique de l’ancienne formule.</p>
                <p class="mt-2 text-sm">Pendant l’attente, les fonctionnalités actuelles restent accessibles et les quotas les plus stricts des deux formules s’appliquent aux nouvelles créations.</p>
                <div class="mt-4 flex flex-wrap gap-3">
                    @if($cmiReadiness['ready'])
                        <form method="POST" action="{{ route('tenant-saas-checkout.store', $pendingChange) }}" data-loading-form>@csrf
                            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                            <x-submit-button label="Régler le changement" loading-label="Préparation…" />
                        </form>
                    @else<p class="text-sm">Paiement en ligne indisponible. Contactez l’administration pour un règlement manuel.</p>@endif
                    <form method="POST" action="{{ route('tenant-saas-plan-change.destroy', $pendingChange) }}">@csrf @method('DELETE')
                        <x-confirmation-button message="Annuler cette demande sans modifier la formule actuelle ?">Annuler la demande</x-confirmation-button>
                    </form>
                </div>
            </x-section-card>
        @elseif($currentSubscription && config('platform_billing.self_service_enabled'))
            <x-section-card title="Changer de formule">
                <div class="mb-5 overflow-x-auto"><table class="rf-table"><caption class="sr-only">Comparer les formules disponibles</caption><thead><tr><th>Formule</th><th>Tarif</th><th>Véhicules</th><th>Agences</th><th>Utilisateurs</th></tr></thead><tbody>@foreach($availablePlans as $plan)@php($limits = app(App\Support\PlatformBilling\SaasPlanEntitlements::class)->normalize($plan->entitlements))<tr><th scope="row">{{ $plan->name }}</th><td>{{ App\Support\Ui\UiLabel::money($plan->price_amount, $plan->currency) }} / {{ $plan->billing_interval->value === 'annual' ? 'an' : 'mois' }}</td>@foreach(['max_vehicles', 'max_agencies', 'max_users'] as $key)<td>{{ $limits[$key] === null ? 'Sans limite contractuelle' : App\Support\Ui\BusinessNumber::integer($limits[$key]) }}</td>@endforeach</tr>@endforeach</tbody></table></div>
                <form method="POST" action="{{ route('tenant-saas-plan-change.store') }}" class="grid gap-4" data-loading-form>@csrf
                    <div><x-input-label for="new-saas-plan" value="Nouvelle formule" required />
                        <select id="new-saas-plan" name="saas_plan_id" required class="mt-1 w-full">
                            @foreach($availablePlans as $plan)<option value="{{ $plan->id }}">{{ $plan->name }} — {{ App\Support\Ui\UiLabel::money($plan->price_amount, $plan->currency) }} / {{ $plan->billing_interval->value === 'annual' ? 'an' : 'mois' }}</option>@endforeach
                        </select>
                    </div>
                    <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="terms_accepted" value="1" required>
                        <span>J’accepte une période commençant maintenant, sans prorata ni remboursement automatique. Le changement devient effectif après règlement (immédiatement pour une offre gratuite). Pendant l’attente de 24 heures maximum, les quotas les plus stricts des deux formules limitent les nouvelles créations. Une baisse sous l’utilisation actuelle est refusée.</span>
                    </label>
                    <x-submit-button label="Demander le changement" loading-label="Vérification des quotas…" />
                </form>
            </x-section-card>
        @endif

        @if($currentSubscription && (config('platform_billing.renewals_enabled') || $currentSubscription->auto_renew))
            <x-section-card title="Renouvellement du service">
                <p class="text-sm">Émission des factures : {{ $currentSubscription->auto_renew ? 'activée' : 'désactivée' }}. Aucun prélèvement automatique. Les factures déjà émises restent dues après désactivation.</p>
                <form method="POST" action="{{ route('tenant-saas-renewal.update', $currentSubscription) }}" class="mt-4 grid gap-3">@csrf @method('PUT')
                    <input type="hidden" name="auto_renew" value="{{ $currentSubscription->auto_renew ? '0' : '1' }}">
                    <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="terms_accepted" value="1" required><span>Je confirme ce choix. À échéance, une facture sera à régler ; un impayé peut entraîner la suspension après le délai de régularisation de {{ config('platform_billing.grace_days') }} jours. La fin d’un essai bloque les nouvelles opérations jusqu’au règlement.</span></label>
                    <x-submit-button :label="$currentSubscription->auto_renew ? 'Désactiver le renouvellement' : 'Activer le renouvellement'" loading-label="Enregistrement…" />
                </form>
            </x-section-card>
        @endif

        <x-section-card title="Factures SaaS">
            <x-responsive-table label="Factures SaaS"><table class="rf-table">
                <thead><tr><th>Référence</th><th>Échéance</th><th>État</th><th>Montant</th></tr></thead>
                <tbody>@forelse($invoices as $invoice)<tr>
                    <td><a class="underline" href="{{ route('tenant-saas-invoices.show', $invoice) }}">{{ $invoice->number }}</a></td>
                    <td>{{ App\Support\Ui\UiLabel::dateTime($invoice->due_at) }}</td>
                    <td>{{ match($invoice->status) { 'paid' => 'Réglée', 'void' => 'Annulée', default => 'À régler' } }}</td>
                    <td>{{ App\Support\Ui\UiLabel::money($invoice->amount, $invoice->currency) }}</td>
                </tr>@empty<tr><td colspan="4">Aucune facture SaaS émise.</td></tr>@endforelse</tbody>
            </table></x-responsive-table>
            {{ $invoices->links() }}
        </x-section-card>

        @php($subscriptionProgress = collect($activationSteps)->where('complete', true)->count())
        <x-section-card title="Activation du service" description="Progression fondée sur votre compte vérifié, la formule attribuée et l’accès effectif au service. Un essai ou une offre gratuite ne nécessite pas de paiement.">
            <x-progress-bar label="Activation de l’abonnement" :value="$subscriptionProgress" :max="3" :value-text="$subscriptionProgress.' étapes sur 3'" />
            <ul class="mt-4 space-y-2 text-sm">@foreach($activationSteps as $step)<li>{{ $step['complete'] ? '✓' : 'À compléter :' }} {{ $step['label'] }}</li>@endforeach</ul>
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
                        @if($cmiReadiness['ready'] && $currentCheckoutAvailable)
                            <form method="POST" action="{{ route('tenant-saas-checkout.store', $currentSubscription) }}" data-loading-form>
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                                <x-submit-button label="Payer par carte avec CMI" loading-label="Préparation du paiement…" icon="payment" class="w-full sm:w-auto" />
                            </form>
                            <p class="mt-2 text-xs leading-5 text-slate-500">Vous serez redirigé vers la page sécurisée de CMI. Aucune donnée de carte n’est saisie ici.</p>
                        @elseif(!$currentCheckoutAvailable)
                            <p class="text-sm text-slate-600">Aucun règlement par carte n’est disponible pour cette formule dans son état actuel. Consultez les factures et toute demande de changement ci-dessus.</p>
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

        <x-section-card title="Utilisation du plan" :description="$planState['reason']">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach($quotas as $quota)
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-sm font-semibold text-slate-800">{{ ucfirst($quota['label']) }}</p>
                        @if($quota['limit'] === null)
                            <p class="mt-2 text-2xl font-bold text-belkhir-space-blue">{{ App\Support\Ui\BusinessNumber::integer($quota['used']) }}</p>
                            <p class="mt-1 text-xs text-slate-500">Sans limite contractuelle</p>
                        @else
                            <div class="mt-3"><x-progress-bar :label="$quota['label']" :value="min($quota['used'], $quota['limit'])" :max="max(1, $quota['limit'])" :value-text="App\Support\Ui\BusinessNumber::integer($quota['used']).' sur '.App\Support\Ui\BusinessNumber::integer($quota['limit'])" :tone="$quota['allowed'] ? 'brand' : 'orange'" /></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-section-card>

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
