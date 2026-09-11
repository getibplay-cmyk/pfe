<x-portal-layout :title="'Facture '.$invoice->invoice_number">
    <div class="flex flex-wrap gap-3 print:hidden"><a class="rf-button-secondary" href="{{ route('portal.home') }}">← Mon espace</a><button type="button" class="rf-button-primary" x-data @click="window.print()">Imprimer / enregistrer en PDF</button></div>
    <x-section-card title="Détail de la facture">
        <p>{{ $invoice->customer_snapshot['name'] ?? $customer->displayName() }}</p>
        @if(filled($invoice->contract_snapshot['number'] ?? null))<p class="mt-2 text-sm">Contrat {{ $invoice->contract_snapshot['number'] }}</p>@endif
        <p class="mt-2 text-sm text-slate-600">Émise le {{ App\Support\Ui\UiLabel::date($invoice->issued_at) }} · Échéance {{ App\Support\Ui\UiLabel::date($invoice->due_at) }}</p>
        <x-status-badge :value="$invoice->status" />
        <x-responsive-table label="Lignes de facture">
            <table class="rf-table mt-4"><thead><tr><th scope="col">Libellé</th><th scope="col">Quantité</th><th scope="col">Prix unitaire</th><th scope="col">Sous-total</th><th scope="col">Taxes</th><th scope="col">Total</th></tr></thead>
                <tbody>@forelse($invoice->lines as $line)<tr>
                    <th scope="row">{{ $line->description }}</th>
                    <td>{{ App\Support\Ui\BusinessNumber::scientificDecimal($line->quantity) }}</td>
                    @foreach(['unit_amount', 'subtotal', 'tax_amount', 'total_amount'] as $amount)<td>{{ App\Support\Ui\UiLabel::money($line->{$amount}, $invoice->currency) }}</td>@endforeach
                </tr>@empty<tr><td colspan="6">Aucune ligne détaillée disponible. Contactez votre agence pour obtenir le détail.</td></tr>@endforelse</tbody>
            </table>
        </x-responsive-table>
        <dl class="mt-5 grid grid-cols-2 gap-3"><dt>Sous-total</dt><dd class="text-right">{{ App\Support\Ui\UiLabel::money($invoice->subtotal, $invoice->currency) }}</dd><dt>Taxes</dt><dd class="text-right">{{ App\Support\Ui\UiLabel::money($invoice->tax_amount, $invoice->currency) }}</dd><dt>Total</dt><dd class="text-right font-bold">{{ App\Support\Ui\UiLabel::money($invoice->total_amount, $invoice->currency) }}</dd><dt>Déjà réglé</dt><dd class="text-right">{{ App\Support\Ui\UiLabel::money($invoice->paid_amount, $invoice->currency) }}</dd><dt>Reste à régler</dt><dd class="text-right font-bold">{{ App\Support\Ui\UiLabel::money($invoice->balance_due, $invoice->currency) }}</dd></dl>
    </x-section-card>
</x-portal-layout>
