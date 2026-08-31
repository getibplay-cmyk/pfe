<x-app-layout>
    <div class="rf-page max-w-4xl">
        <x-page-header :title="'Enregistrer un paiement SaaS · '.$tenant->name" eyebrow="Administration de la plateforme" description="Saisie administrative manuelle, sans carte ni appel externe."><x-slot:actions><a href="{{ route('platform.tenants.show', $tenant) }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />Retour à l’entreprise</a></x-slot:actions></x-page-header>
        <x-form-errors />
        @if($subscription === null)
            <x-empty-state title="Aucun abonnement courant" description="Créez d’abord un abonnement pour rattacher cette écriture au bon plan et à sa devise." />
        @else
            <x-section-card :title="$subscription->plan->name" :description="'Devise de l’abonnement : '.$subscription->currency">
                <form method="POST" action="{{ route('platform.tenants.saas-payments.store', [$tenant, $subscription]) }}" class="grid gap-4 md:grid-cols-2">@csrf
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                    <div><x-input-label for="saas-payment-amount" :value="'Montant ('.$subscription->currency.')'" required /><input id="saas-payment-amount" name="amount" inputmode="decimal" required pattern="\d+(\.\d{1,2})?" value="{{ old('amount') }}" class="mt-1 w-full"><x-field-error :messages="$errors->get('amount')" /></div>
                    <div><x-input-label for="saas-payment-method" value="Moyen manuel" required /><select id="saas-payment-method" name="payment_method" required class="mt-1 w-full">@foreach($methods as $method)<option value="{{ $method->value }}" @selected(old('payment_method') === $method->value)>{{ App\Support\Ui\UiLabel::get($method->value) }}</option>@endforeach</select><x-field-error :messages="$errors->get('payment_method')" /></div>
                    <div><x-input-label for="saas-payment-received" value="Date de réception" required /><input id="saas-payment-received" type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" required class="mt-1 w-full"><x-field-error :messages="$errors->get('occurred_at')" /></div>
                    <div><x-input-label for="saas-payment-reference" value="Référence administrative" /><input id="saas-payment-reference" name="reference" value="{{ old('reference') }}" maxlength="100" class="mt-1 w-full"><p class="mt-1 text-xs text-slate-500">N’inscrivez aucun numéro de carte ou de compte.</p><x-field-error :messages="$errors->get('reference')" /></div>
                    <div class="md:col-span-2"><x-input-label for="saas-payment-note" value="Note" /><textarea id="saas-payment-note" name="note" maxlength="4000" rows="3" class="mt-1 w-full">{{ old('note') }}</textarea><x-field-error :messages="$errors->get('note')" /></div>
                    <div class="md:col-span-2"><x-confirmation-button message="Confirmer cette écriture administrative manuelle ?">Enregistrer le paiement manuel</x-confirmation-button></div>
                </form>
            </x-section-card>
        @endif
    </div>
</x-app-layout>
