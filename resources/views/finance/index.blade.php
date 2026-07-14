<x-app-layout>
    <div class="space-y-8">
        <div><p class="text-sm text-slate-500">Lot 05</p><h1 class="text-3xl font-bold">Finance et clôture</h1><p class="mt-2 text-sm text-slate-600">Registres opérationnels uniquement — aucune comptabilité générale ni passerelle bancaire.</p></div>
        <section class="rounded-xl bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">Factures</h2>
            <div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-slate-500"><th class="py-2">Numéro</th><th>Statut</th><th>Total</th><th>Payé</th><th>Solde</th></tr></thead><tbody>
                @forelse($invoices as $invoice)<tr class="border-t"><td class="py-3"><a class="font-medium text-indigo-700" href="{{ route('finance.invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a></td><td>{{ $invoice->status }}</td><td>{{ $invoice->total_amount }} {{ $invoice->currency }}</td><td>{{ $invoice->paid_amount }}</td><td>{{ $invoice->balance_due }}</td></tr>@empty<tr><td colspan="5" class="py-8 text-slate-500">Aucune facture.</td></tr>@endforelse
            </tbody></table></div>{{ $invoices->links() }}
        </section>
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl bg-white p-6 shadow-sm"><h2 class="font-semibold">Paiements récents</h2><div class="mt-4 space-y-3 text-sm">@forelse($payments as $payment)<div class="border-b pb-3"><p class="font-medium">{{ $payment->payment_number }} · {{ $payment->amount }} {{ $payment->currency }}</p><p class="text-slate-500">{{ $payment->status }} · {{ $payment->payment_method }}</p></div>@empty<p class="text-slate-500">Aucun paiement.</p>@endforelse</div></section>
            <section class="rounded-xl bg-white p-6 shadow-sm"><h2 class="font-semibold">Caution</h2><div class="mt-4 space-y-3 text-sm">@forelse($deposits as $entry)<div class="border-b pb-3"><p class="font-medium">{{ $entry->transaction_number }} · {{ $entry->amount }} {{ $entry->currency }}</p><p class="text-slate-500">{{ $entry->transaction_type }}</p></div>@empty<p class="text-slate-500">Aucun mouvement.</p>@endforelse</div></section>
            <section class="rounded-xl bg-white p-6 shadow-sm"><h2 class="font-semibold">Dépenses</h2><div class="mt-4 space-y-3 text-sm">@forelse($expenses as $expense)<div class="border-b pb-3"><p class="font-medium">{{ $expense->expense_number }} · {{ $expense->amount }} {{ $expense->currency }}</p><p class="text-slate-500">{{ $expense->category }} · {{ $expense->status }}</p></div>@empty<p class="text-slate-500">Aucune dépense.</p>@endforelse</div></section>
        </div>
    </div>
</x-app-layout>
