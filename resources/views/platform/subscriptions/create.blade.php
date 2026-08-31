<x-app-layout>
    <div class="rf-page max-w-4xl">
        <x-page-header :title="'Nouvel abonnement · '.$tenant->name" eyebrow="Administration de la plateforme" description="Le tarif du plan sera figé dans cet abonnement ; aucun paiement ne sera généré automatiquement."><x-slot:actions><a href="{{ route('platform.tenants.show', $tenant) }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />Retour à l’entreprise</a></x-slot:actions></x-page-header>
        <x-form-errors />
        @if($currentSubscription)
            <x-section-card title="Abonnement courant"><p class="text-sm">{{ $currentSubscription->plan->name }} · <x-status-badge :value="$currentSubscription->status" /></p><p class="mt-2 text-sm text-slate-600">Terminez d’abord cet abonnement depuis l’historique avant d’en créer un autre.</p></x-section-card>
        @elseif($plans->isEmpty())
            <x-empty-state title="Aucun plan actif" description="Créez ou réactivez un plan avant d’affecter un abonnement." />
        @else
            <x-section-card title="Conditions de l’abonnement">
                <form method="POST" action="{{ route('platform.tenants.subscriptions.store', $tenant) }}" class="grid gap-4 md:grid-cols-2">@csrf
                    <div><x-input-label for="subscription-plan" value="Plan" required /><select id="subscription-plan" name="saas_plan_id" required class="mt-1 w-full">@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected((string) old('saas_plan_id') === (string) $plan->id)>{{ $plan->name }} · {{ App\Support\Ui\UiLabel::money($plan->price_amount, $plan->currency) }}</option>@endforeach</select><x-field-error :messages="$errors->get('saas_plan_id')" /></div>
                    <div><x-input-label for="subscription-initial-status" value="État initial" required /><select id="subscription-initial-status" name="status" required class="mt-1 w-full">@foreach($initialStatuses as $status)<option value="{{ $status->value }}" @selected(old('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status->value) }}</option>@endforeach</select><x-field-error :messages="$errors->get('status')" /></div>
                    <div><x-input-label for="subscription-start" value="Début" required /><input id="subscription-start" type="datetime-local" name="starts_at" value="{{ old('starts_at', now()->format('Y-m-d\TH:i')) }}" required class="mt-1 w-full"><x-field-error :messages="$errors->get('starts_at')" /></div>
                    <div><x-input-label for="subscription-end" value="Fin facultative" /><input id="subscription-end" type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" class="mt-1 w-full"><x-field-error :messages="$errors->get('ends_at')" /></div>
                    <div><x-input-label for="subscription-trial-end" value="Fin de période d’essai" /><input id="subscription-trial-end" type="datetime-local" name="trial_ends_at" value="{{ old('trial_ends_at') }}" class="mt-1 w-full"><p class="mt-1 text-xs text-slate-500">Obligatoire si l’état initial est « Période d’essai ».</p><x-field-error :messages="$errors->get('trial_ends_at')" /></div>
                    <div><x-input-label for="subscription-renewal" value="Prochain renouvellement" /><input id="subscription-renewal" type="datetime-local" name="next_renewal_at" value="{{ old('next_renewal_at') }}" class="mt-1 w-full"><x-field-error :messages="$errors->get('next_renewal_at')" /></div>
                    <div class="md:col-span-2"><x-input-label for="subscription-note" value="Note administrative" /><textarea id="subscription-note" name="admin_note" maxlength="4000" rows="3" class="mt-1 w-full">{{ old('admin_note') }}</textarea><x-field-error :messages="$errors->get('admin_note')" /></div>
                    <div class="md:col-span-2"><x-confirmation-button message="Créer cet abonnement sans paiement automatique ?">Créer l’abonnement</x-confirmation-button></div>
                </form>
            </x-section-card>
        @endif
    </div>
</x-app-layout>
