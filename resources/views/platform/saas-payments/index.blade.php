<x-app-layout>
    @php($activeFilterCount = collect(['q', 'entry_type'])->filter(fn (string $key): bool => request()->filled($key))->count())
    <div class="rf-page">
        <x-page-header :title="__('Paiements SaaS')" :eyebrow="__('Administration de la plateforme')" :description="__('Registre des règlements manuels et des transactions CMI entre les entreprises et ').config('brand.name').'.'"></x-page-header>
        <div class="rounded-xl border p-4 text-sm {{ $cmiReadiness['ready'] ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-amber-200 bg-amber-50 text-amber-950' }}"><strong>{{ __('Passerelle CMI :') }}</strong> {{ $cmiReadiness['message'] }} {{ __('Les données de carte ne transitent jamais par cette application.') }}</div>
        <x-form-errors />
        <x-section-card :title="__('Dernières tentatives CMI')" :description="__('Chaque résultat repose sur un callback signé ; le retour navigateur n’est jamais considéré comme une preuve de paiement.')">
            <x-responsive-table :label="__('Tentatives de paiement CMI')" class="shadow-none"><table><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Entreprise') }}</th><th>{{ __('Offre') }}</th><th>{{ __('Référence commande') }}</th><th>{{ __('État') }}</th><th>{{ __('Code') }}</th><th class="text-end">{{ __('Montant') }}</th></tr></thead><tbody>
                @forelse($attempts as $attempt)<tr><td>{{ App\Support\Ui\UiLabel::dateTime($attempt->created_at) }}</td><td>{{ $attempt->tenant->name }}</td><td>{{ $attempt->subscription->plan->name }}</td><td>{{ $attempt->merchant_order_id }}</td><td><x-status-badge :value="$attempt->status" /></td><td>{{ $attempt->gateway_response_code ?? '—' }}</td><td class="text-end">{{ App\Support\Ui\UiLabel::money($attempt->amount, $attempt->currency) }}</td></tr>
                @empty<tr><td colspan="7"><x-empty-state :title="__('Aucune tentative CMI')" /></td></tr>@endforelse
            </tbody></table></x-responsive-table>
        </x-section-card>
        <x-filter-panel :title="__('Filtrer les paiements SaaS')" :active-count="$activeFilterCount" :result-count="$payments->total()"><form class="rf-filter-grid" method="GET" data-loading-form><div><x-input-label for="saas-payment-search" :value="__('Entreprise ou référence')" /><input id="saas-payment-search" name="q" value="{{ request('q') }}" class="mt-1 w-full"></div><div><x-input-label for="saas-payment-type" :value="__('Type d’écriture')" /><select id="saas-payment-type" name="entry_type" class="mt-1 w-full"><option value="">{{ __('Tous') }}</option>@foreach($entryTypes as $type)<option value="{{ $type->value }}" @selected(request('entry_type') === $type->value)>{{ $type->value === 'payment' ? __('Paiement enregistré') : __('Contrepassation') }}</option>@endforeach</select></div><div class="flex items-end gap-2"><x-submit-button :label="__('Filtrer')" loading-label="{{ __('Filtrage…') }}" />@if($activeFilterCount > 0)<a href="{{ route('platform.saas-payments.index') }}" class="rf-button-secondary"><x-icon name="reset" /> {{ __('Effacer') }}</a>@endif</div></form></x-filter-panel>
        <x-responsive-table :label="__('Registre comptable des paiements SaaS')"><table><thead><tr><th>{{ __('Date de réception') }}</th><th>{{ __('Entreprise') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Type') }}</th><th>{{ __('Moyen') }}</th><th>{{ __('Référence') }}</th><th class="text-end">{{ __('Montant') }}</th><th class="text-end">{{ __('Action') }}</th></tr></thead><tbody>
            @forelse($payments as $payment)<tr><td>{{ App\Support\Ui\UiLabel::dateTime($payment->occurred_at) }}</td><td>{{ $payment->tenant->name }}</td><td>{{ $payment->subscription->plan->name }}</td><td><x-status-badge :value="$payment->entry_type" /></td><td>{{ App\Support\Ui\UiLabel::get($payment->payment_method->value) }}</td><td>{{ $payment->reference ?? '—' }}</td><td class="text-end">{{ $payment->entry_type->value === 'reversal' ? '−' : '' }}{{ App\Support\Ui\UiLabel::money($payment->amount, $payment->currency) }}</td><td class="text-end">
                @if($payment->entry_type->value === 'payment' && $payment->reversal === null)
                    @php($isCmiPayment = $payment->payment_method->value === 'cmi')
                    <details class="inline-block text-start">
                        <summary class="rf-button-secondary cursor-pointer"><x-icon name="warning" size="xs" />{{ $isCmiPayment ? __('Enregistrer le remboursement') : __('Contrepasser') }}</summary>
                        <form method="POST" action="{{ route('platform.saas-payments.reverse', $payment) }}" class="mt-2 w-80 space-y-3 rounded-xl border bg-white p-4 shadow-xl" x-belkhir-space-confirm data-confirm-title="{{ $isCmiPayment ? __('Enregistrer le remboursement CMI') : __('Contrepasser le paiement SaaS') }}" data-confirm-resource="{{ __('Paiement SaaS sélectionné') }}" data-confirm-consequence="{{ __('Une écriture de correction définitive sera créée ; le paiement d’origine restera conservé.') }}" data-confirm-label="{{ __('Enregistrer la contrepassation') }}" data-loading-form>
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                            @if($payment->saas_invoice_id)
                                <p class="text-xs text-amber-950">{{ __('Cette correction rouvre la dette de renouvellement ou annule la facture de changement et peut suspendre le service. L’ancien abonnement ne sera pas réactivé automatiquement.') }}</p>
                            @endif
                            @if($isCmiPayment)
                                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-950">{{ __('Effectuez d’abord le remboursement dans le portail marchand CMI. BELKHIR SPACE enregistre ensuite sa référence sans appeler ni simuler la passerelle.') }}</div>
                                <label class="flex items-start gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="cmi_refund_confirmed" value="1" required class="mt-1 rounded border-slate-300 text-blue-700 focus:ring-blue-600">
                                    <span>{{ __('Je confirme que le remboursement a été accepté dans le portail CMI.') }}</span>
                                </label>
                            @endif
                            <div>
                                <x-input-label :for="'reversal-reason-'.$payment->id" :value="__('Motif')" required />
                                <textarea id="reversal-reason-{{ $payment->id }}" name="reason" required maxlength="2000" class="mt-1 w-full"></textarea>
                                <x-field-error :messages="$errors->get('reason')" />
                            </div>
                            <div>
                                <x-input-label :for="'reversal-reference-'.$payment->id" :value="$isCmiPayment ? __('Référence du remboursement CMI') : __('Référence facultative')" :required="$isCmiPayment" />
                                <input id="reversal-reference-{{ $payment->id }}" name="reference" maxlength="100" @required($isCmiPayment) class="mt-1 w-full">
                                <x-field-error :messages="$errors->get('reference')" />
                            </div>
                            <x-submit-button variant="danger" :label="__('Enregistrer la contrepassation')" loading-label="{{ __('Enregistrement…') }}" />
                        </form>
                    </details>
                @elseif($payment->entry_type->value === 'payment')
                    <span class="text-sm text-slate-500">{{ __('Contrepassé') }}</span>
                @else
                    <span class="text-sm text-slate-500">{{ __('Écriture finale') }}</span>
                @endif
            </td></tr>@empty<tr><td colspan="8"><x-empty-state :title="__('Aucun paiement SaaS')" :description="__('Les registres locatifs existants restent inchangés.')" /></td></tr>@endforelse
        </tbody></table><x-slot:footer>{{ $payments->links() }}</x-slot:footer></x-responsive-table>
    </div>
</x-app-layout>
