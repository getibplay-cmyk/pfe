<x-portal-layout :title="__('Facture ').$invoice->invoice_number">
    <div class="flex flex-wrap gap-3 print:hidden"><a class="rf-button-secondary" href="{{ route('portal.home') }}">{{ __('← Mon espace') }}</a><button type="button" class="rf-button-primary" x-data @click="window.print()">{{ __('Imprimer / enregistrer en PDF') }}</button></div>
    <x-section-card :title="__('Détail de la facture')">
        <p>{{ $invoice->customer_snapshot['name'] ?? $customer->displayName() }}</p>
        @if(filled($invoice->contract_snapshot['number'] ?? null))<p class="mt-2 text-sm">{{ __('Contrat') }} {{ $invoice->contract_snapshot['number'] }}</p>@endif
        <p class="mt-2 text-sm text-slate-600">{{ __('Émise le') }} {{ App\Support\Ui\UiLabel::date($invoice->issued_at) }} {{ __('· Échéance') }} {{ App\Support\Ui\UiLabel::date($invoice->due_at) }}</p>
        <x-status-badge :value="$invoice->status" />
        <x-responsive-table :label="__('Lignes de facture')">
            <table class="rf-table mt-4"><thead><tr><th scope="col">{{ __('Libellé') }}</th><th scope="col">{{ __('Quantité') }}</th><th scope="col">{{ __('Prix unitaire') }}</th><th scope="col">{{ __('Sous-total') }}</th><th scope="col">{{ __('Taxes') }}</th><th scope="col">{{ __('Total') }}</th></tr></thead>
                <tbody>@forelse($invoice->lines as $line)<tr>
                    <th scope="row">{{ $line->description }}</th>
                    <td>{{ App\Support\Ui\BusinessNumber::scientificDecimal($line->quantity) }}</td>
                    @foreach(['unit_amount', 'subtotal', 'tax_amount', 'total_amount'] as $amount)<td>{{ App\Support\Ui\UiLabel::money($line->{$amount}, $invoice->currency) }}</td>@endforeach
                </tr>@empty<tr><td colspan="6">{{ __('Aucune ligne détaillée disponible. Contactez votre agence pour obtenir le détail.') }}</td></tr>@endforelse</tbody>
            </table>
        </x-responsive-table>
        <dl class="mt-5 grid grid-cols-2 gap-3"><dt>{{ __('Sous-total') }}</dt><dd class="text-end">{{ App\Support\Ui\UiLabel::money($invoice->subtotal, $invoice->currency) }}</dd><dt>{{ __('Taxes') }}</dt><dd class="text-end">{{ App\Support\Ui\UiLabel::money($invoice->tax_amount, $invoice->currency) }}</dd><dt>{{ __('Total') }}</dt><dd class="text-end font-bold">{{ App\Support\Ui\UiLabel::money($invoice->total_amount, $invoice->currency) }}</dd><dt>{{ __('Déjà réglé') }}</dt><dd class="text-end">{{ App\Support\Ui\UiLabel::money($invoice->paid_amount, $invoice->currency) }}</dd><dt>{{ __('Reste à régler') }}</dt><dd class="text-end font-bold">{{ App\Support\Ui\UiLabel::money($invoice->balance_due, $invoice->currency) }}</dd></dl>
    </x-section-card>
</x-portal-layout>
