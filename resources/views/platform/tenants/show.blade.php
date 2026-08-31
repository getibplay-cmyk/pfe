<x-app-layout>
    <div class="rf-page">
        <x-page-header :title="$tenant->name" eyebrow="Entreprise cliente" description="Structure, abonnement, paiements et fonctionnalités intelligentes autorisées." :breadcrumbs="[['label' => 'Entreprises clientes', 'url' => route('platform.tenants.index')], ['label' => $tenant->name]]">
            <x-slot:actions><x-status-badge :value="$tenant->status" /><a href="{{ route('platform.tenants.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />Retour aux entreprises</a><a href="{{ route('platform.tenants.edit', $tenant) }}" class="rf-button-primary"><x-icon name="edit" size="xs" />Modifier</a></x-slot:actions>
        </x-page-header>

        <x-form-errors />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">@foreach($counts as $label => $value)<x-stat-card :label="$label" :value="$value" />@endforeach</div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Informations générales">
                <x-metadata-list>
                    <x-metadata-item label="Raison sociale">{{ $tenant->legal_name ?? '—' }}</x-metadata-item>
                    <x-metadata-item label="E-mail">{{ $tenant->email ?? '—' }}</x-metadata-item>
                    <x-metadata-item label="Propriétaire actif">{{ $owner?->name ?? 'Absent' }} @if($owner)<span class="block text-slate-500">{{ $owner->email }}</span>@endif</x-metadata-item>
                    <x-metadata-item label="Devise / fuseau">{{ $tenant->settings['currency'] ?? 'MAD' }} / {{ $tenant->settings['timezone'] ?? 'Africa/Casablanca' }}</x-metadata-item>
                    <x-metadata-item label="Création">{{ App\Support\Ui\UiLabel::date($tenant->created_at) }}</x-metadata-item>
                </x-metadata-list>
            </x-section-card>
            <x-section-card title="Agences">
                <div class="divide-y">@forelse($agencies as $agency)<div class="flex justify-between py-3 text-sm"><span><strong>{{ $agency->name }}</strong><span class="block text-slate-500">{{ $agency->code }}</span></span><x-status-badge :value="$agency->is_active ? 'active' : 'inactive'" /></div>@empty<x-empty-state title="Aucune agence" />@endforelse</div>
            </x-section-card>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Abonnement courant">
                <x-slot:actions>
                    @if(! $currentSubscription && $hasActivePlans)
                        <a href="{{ route('platform.tenants.subscriptions.create', $tenant) }}" class="rf-button-primary"><x-icon name="add" size="xs" />Attribuer un abonnement</a>
                    @elseif(! $currentSubscription)
                        <span class="text-sm text-slate-500">Aucun plan actif</span>
                    @endif
                </x-slot:actions>
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
                        @if($currentSubscription->admin_note)<x-metadata-item label="Note administrative">{{ $currentSubscription->admin_note }}</x-metadata-item>@endif
                    </x-metadata-list>
                @else<x-empty-state title="Aucun abonnement courant" description="L’entreprise conserve son accès historique ; un abonnement peut être attribué depuis l’administration." />@endif
            </x-section-card>

            <x-section-card title="Paiements SaaS manuels" description="Registre administratif distinct des paiements de location.">
                <x-slot:actions>
                    @if($currentSubscription)<a href="{{ route('platform.tenants.saas-payments.create', $tenant) }}" class="rf-button-primary"><x-icon name="payment" size="xs" />Enregistrer un paiement</a>
                    @else<span class="text-sm text-slate-500">Abonnement requis</span>@endif
                </x-slot:actions>
                <div class="divide-y">@forelse($saasPayments as $payment)<div class="flex items-start justify-between gap-4 py-3 text-sm"><span><strong>{{ $payment->entry_type->value === 'reversal' ? 'Contrepassation' : 'Paiement enregistré' }}</strong><span class="block text-slate-500">{{ App\Support\Ui\UiLabel::get($payment->payment_method) }} · {{ App\Support\Ui\UiLabel::dateTime($payment->occurred_at) }}</span>@if($payment->reason)<span class="mt-1 block text-slate-600">Motif : {{ $payment->reason }}</span>@endif @if($payment->note)<span class="mt-1 block text-slate-600">{{ $payment->note }}</span>@endif</span><strong>{{ $payment->entry_type->value === 'reversal' ? '−' : '' }}{{ App\Support\Ui\UiLabel::money($payment->amount, $payment->currency) }}</strong></div>@empty<x-empty-state title="Aucun paiement SaaS" />@endforelse</div>
            </x-section-card>
        </div>

        <x-section-card title="Assistances intelligentes" description="Une autorisation ne lance aucune analyse et ne modifie aucune donnée métier.">
            @php
                $capabilityTotal = collect($capabilities)->count();
                $enabledCapabilityCount = collect($capabilities)->where('enabled', true)->count();
            @endphp
            @if($capabilityTotal > 0)
                <x-progress-bar
                    class="mb-5 max-w-2xl"
                    label="Fonctionnalités autorisées pour cette entreprise"
                    :value="$enabledCapabilityCount"
                    :max="$capabilityTotal"
                    :value-text="App\Support\Ui\BusinessNumber::integer($enabledCapabilityCount).' sur '.App\Support\Ui\BusinessNumber::integer($capabilityTotal)"
                    tone="orange"
                />
            @endif
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach($capabilities as $capability)
                    <article class="rf-interactive-card rounded-xl border border-slate-200 p-4">
                        <div class="flex items-start justify-between gap-3"><h3 class="font-semibold">{{ $capability['label'] }}</h3><x-status-badge :value="$capability['usable'] ? 'active' : 'inactive'" /></div>
                        <p class="mt-2 text-sm text-slate-600">{{ $capability['message'] }}</p>
                        <dl class="mt-3 space-y-1 text-xs text-slate-500"><div class="flex justify-between gap-2"><dt>Autorisation entreprise</dt><dd>{{ $capability['enabled'] ? 'Activée' : 'Désactivée' }}</dd></div><div class="flex justify-between gap-2"><dt>Service disponible</dt><dd>{{ $capability['available'] ? 'Oui' : 'Non' }}</dd></div></dl>
                        @if($capability['changed_at'])<p class="mt-3 text-xs text-slate-500">Dernière modification le {{ App\Support\Ui\UiLabel::dateTime($capability['changed_at']) }}@if($capability['updated_by_name']) par {{ $capability['updated_by_name'] }}@endif.</p>@endif
                    </article>
                @endforeach
            </div>
        </x-section-card>

        <x-section-card title="Historique administratif" description="Actions de plateforme uniquement ; aucun détail sensible n’est affiché.">
            <ol class="divide-y">@forelse($administrativeHistory as $event)<li class="py-3 text-sm"><div class="flex flex-wrap justify-between gap-2"><strong>{{ match($event->action) {'platform.tenant.provisioned' => 'Entreprise créée', 'platform.tenant.updated' => 'Entreprise mise à jour', 'platform.tenant.suspended' => 'Entreprise suspendue', 'platform.tenant.reactivated' => 'Entreprise réactivée', 'platform.subscription.created' => 'Abonnement créé', 'platform.subscription.status_changed' => 'État de l’abonnement modifié', 'platform.saas_payment.recorded' => 'Paiement SaaS enregistré', 'platform.saas_payment.reversed' => 'Paiement SaaS contrepassé', 'platform.intelligence_access.updated' => 'Autorisation d’une assistance modifiée', default => 'Action administrative enregistrée'} }}</strong><time>{{ App\Support\Ui\UiLabel::dateTime(Carbon\CarbonImmutable::parse($event->created_at)) }}</time></div><p class="mt-1 text-slate-500">{{ $event->actor_name ?? 'Administration de la plateforme' }}</p></li>@empty<li class="py-3"><x-empty-state title="Aucune action administrative" /></li>@endforelse</ol>
        </x-section-card>

        <x-section-card title="État de service">
            @if($tenant->status->value === 'active')
                <form method="POST" action="{{ route('platform.tenants.suspend', $tenant) }}" class="space-y-3">@csrf<div><x-input-label for="tenant-suspension-reason" value="Motif de suspension" required /><textarea id="tenant-suspension-reason" name="reason" required maxlength="2000" rows="3" aria-describedby="tenant-suspension-help tenant-suspension-error" class="mt-1 w-full">{{ old('reason') }}</textarea><p id="tenant-suspension-help" class="mt-1 text-xs text-slate-500">Ne saisissez aucune donnée personnelle sensible.</p><x-field-error id="tenant-suspension-error" :messages="$errors->get('reason')" /></div><x-confirmation-button title="Suspendre l’entreprise" resource="Entreprise cliente sélectionnée" message="Les sessions seront révoquées et l’accès de cette entreprise sera suspendu sans supprimer ses données." confirm-label="Suspendre l’entreprise">Suspendre l’entreprise</x-confirmation-button></form>
            @elseif($tenant->status->value === 'suspended')
                <div class="rounded bg-amber-50 p-4 text-sm"><p><strong>Motif :</strong> {{ $tenant->suspension_reason }}</p><p class="mt-1 text-slate-600">Suspendue le {{ App\Support\Ui\UiLabel::dateTime($tenant->suspended_at) }}</p></div><form method="POST" action="{{ route('platform.tenants.reactivate', $tenant) }}" class="mt-4">@csrf<x-confirmation-button message="Réactiver cette entreprise ?">Réactiver l’entreprise</x-confirmation-button></form>
            @else<p class="text-sm text-slate-500">Cette entreprise archivée ne peut pas être modifiée depuis ce parcours.</p>@endif
        </x-section-card>
    </div>
</x-app-layout>
