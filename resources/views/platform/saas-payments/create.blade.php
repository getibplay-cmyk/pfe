<x-app-layout>
    <div class="rf-page max-w-4xl">
        <x-page-header :title="__('Enregistrer un paiement SaaS · ').$tenant->name" :eyebrow="__('Administration de la plateforme')" :description="__('Saisie administrative manuelle, sans carte ni appel externe.')"><x-slot:actions><a href="{{ route('platform.tenants.show', $tenant) }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />{{ __('Retour à l’entreprise') }}</a></x-slot:actions></x-page-header>
        <x-form-errors />
        @if($subscription === null)
            <x-empty-state :title="__('Aucun abonnement courant')" :description="__('Créez d’abord un abonnement pour rattacher cette écriture au bon plan et à sa devise.')" />
        @else
            <x-section-card :title="$subscription->plan->name" :description="__('Devise de l’abonnement : ').$subscription->currency">
                <form method="POST" action="{{ route('platform.tenants.saas-payments.store', [$tenant, $subscription]) }}" class="grid gap-4 md:grid-cols-2">@csrf
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                    @if($requiresInvoice)
                        <div class="md:col-span-2"><x-input-label for="saas-invoice-id" :value="__('Facture à régler intégralement')" required />
                            <select id="saas-invoice-id" name="saas_invoice_id" required class="mt-1 w-full">
                                @foreach($invoices as $invoice)<option value="{{ $invoice->id }}">{{ $invoice->number }} — {{ App\Support\Ui\UiLabel::money($invoice->amount, $invoice->currency) }}</option>@endforeach
                            </select>
                            @if($invoices->isEmpty())<p>{{ __('Aucune facture ouverte : aucun nouveau paiement ne peut être affecté.') }}</p>@endif
                        </div>
                    @endif
                    <div><x-input-label for="saas-payment-amount" :value="__('Montant (').$subscription->currency.')'" required /><input id="saas-payment-amount" name="amount" inputmode="decimal" required pattern="\d+(\.\d{1,2})?" value="{{ old('amount') }}" class="mt-1 w-full"><x-field-error :messages="$errors->get('amount')" /></div>
                    <div><x-input-label for="saas-payment-method" :value="__('Moyen manuel')" required /><select id="saas-payment-method" name="payment_method" required class="mt-1 w-full">@foreach($methods as $method)<option value="{{ $method->value }}" @selected(old('payment_method') === $method->value)>{{ App\Support\Ui\UiLabel::get($method->value) }}</option>@endforeach</select><x-field-error :messages="$errors->get('payment_method')" /></div>
                    <div><x-input-label for="saas-payment-received" :value="__('Date de réception')" required /><input id="saas-payment-received" type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" required class="mt-1 w-full"><x-field-error :messages="$errors->get('occurred_at')" /></div>
                    <div><x-input-label for="saas-payment-reference" :value="__('Référence administrative')" /><input id="saas-payment-reference" name="reference" value="{{ old('reference') }}" maxlength="100" class="mt-1 w-full"><p class="mt-1 text-xs text-slate-500">{{ __('N’inscrivez aucun numéro de carte ou de compte.') }}</p><x-field-error :messages="$errors->get('reference')" /></div>
                    <div class="md:col-span-2"><x-input-label for="saas-payment-note" :value="__('Note')" /><textarea id="saas-payment-note" name="note" maxlength="4000" rows="3" class="mt-1 w-full">{{ old('note') }}</textarea><x-field-error :messages="$errors->get('note')" /></div>
                    <div class="md:col-span-2"><x-confirmation-button :message="__('Confirmer cette écriture administrative manuelle ?')">{{ __('Enregistrer le paiement manuel') }}</x-confirmation-button></div>
                </form>
            </x-section-card>
        @endif
    </div>
</x-app-layout>
