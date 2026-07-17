<x-app-layout>
    <div class="space-y-8">
        <x-page-header title="Finance et clôture" eyebrow="Registres opérationnels" description="Chaque registre est affiché selon vos permissions. Aucune comptabilité générale ni passerelle bancaire." />
        <x-form-errors />

        @if($invoices !== null)
            <form method="GET" class="grid gap-3 rounded-xl bg-white p-4 sm:grid-cols-3">
                <input name="q" value="{{ request('q') }}" placeholder="Numéro de facture" aria-label="Numéro de facture">
                <select name="invoice_status" aria-label="Statut de facture">
                    <option value="">Tous les statuts</option>
                    @foreach(['draft','issued','partially_paid','paid','void'] as $status)
                        <option value="{{ $status }}" @selected(request('invoice_status') === $status)>{{ App\Support\Ui\UiLabel::get($status) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Filtrer les factures</button>
            </form>

            <section class="rounded-xl bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold">Factures</h2><x-result-count :paginator="$invoices" /></div>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-slate-500"><th class="p-3">Numéro</th><th class="p-3">Contrat</th><th class="p-3">Statut</th><th class="p-3">Total</th><th class="p-3">Payé</th><th class="p-3">Solde</th></tr></thead>
                        <tbody>@forelse($invoices as $invoice)<tr class="border-t"><td class="p-3"><a class="font-medium text-indigo-700" href="{{ route('finance.invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a></td><td class="p-3"><a class="underline" href="{{ route('contracts.show', $invoice->rentalContract) }}">{{ $invoice->rentalContract->contract_number }}</a></td><td class="p-3"><x-status-badge :value="$invoice->status" /></td><td class="p-3">{{ App\Support\Ui\UiLabel::money($invoice->total_amount, $invoice->currency) }}</td><td class="p-3">{{ App\Support\Ui\UiLabel::money($invoice->paid_amount, $invoice->currency) }}</td><td class="p-3">{{ App\Support\Ui\UiLabel::money($invoice->balance_due, $invoice->currency) }}</td></tr>@empty<tr><td colspan="6" class="p-8 text-slate-500">Aucune facture ne correspond aux filtres.</td></tr>@endforelse</tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $invoices->links() }}</div>
            </section>
        @endif

        @if($payments !== null || $deposits !== null)
            <div class="grid gap-6 lg:grid-cols-2">
                @if($payments !== null)
                    <section class="rounded-xl bg-white p-6 shadow-sm">
                        <h2 class="font-semibold">Paiements récents</h2>
                        <div class="mt-4 space-y-3 text-sm">@forelse($payments as $payment)<div class="border-b pb-3"><p class="font-medium">{{ $payment->payment_number }} · {{ App\Support\Ui\UiLabel::money($payment->amount, $payment->currency) }}</p><p class="text-slate-500">{{ App\Support\Ui\UiLabel::get($payment->status) }} · {{ App\Support\Ui\UiLabel::get($payment->payment_method) }}@if($payment->rentalContract) · <a class="underline" href="{{ route('contracts.show', $payment->rentalContract) }}">{{ $payment->rentalContract->contract_number }}</a>@endif</p>@foreach($payment->allocations as $allocation)<a class="text-xs text-indigo-700 underline" href="{{ route('finance.invoices.show', $allocation->invoice) }}">Alloué à {{ $allocation->invoice->invoice_number }}</a>@endforeach</div>@empty<x-empty-state title="Aucun paiement" />@endforelse</div>
                    </section>
                @endif
                @if($deposits !== null)
                    <section class="rounded-xl bg-white p-6 shadow-sm">
                        <h2 class="font-semibold">Cautions</h2>
                        <div class="mt-4 space-y-3 text-sm">@forelse($deposits as $entry)<div class="border-b pb-3"><p class="font-medium">{{ $entry->transaction_number }} · {{ App\Support\Ui\UiLabel::money($entry->amount, $entry->currency) }}</p><p class="text-slate-500">{{ App\Support\Ui\UiLabel::get($entry->transaction_type) }} · <a class="underline" href="{{ route('contracts.show', $entry->rentalContract) }}">{{ $entry->rentalContract->contract_number }}</a></p></div>@empty<x-empty-state title="Aucun mouvement de caution" />@endforelse</div>
                    </section>
                @endif
            </div>
        @endif

        @if(auth()->user()->hasPermission('expense.create'))
            <section class="rounded-xl bg-white p-6 shadow-sm">
                <h2 class="font-semibold">Créer une dépense</h2>
                <form method="POST" action="{{ route('finance.expenses.store') }}" class="mt-4 grid gap-3 md:grid-cols-3">
                    @csrf
                    <label class="text-sm">Agence *<select name="agency_id" required class="mt-1 w-full"><option value="">Choisir</option>@foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected(old('agency_id') == $agency->id)>{{ $agency->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('agency_id')" /></label>
                    <label class="text-sm">Catégorie *<select name="category" class="mt-1 w-full">@foreach(['maintenance','insurance','fuel','cleaning','administration','other'] as $category)<option value="{{ $category }}" @selected(old('category') === $category)>{{ App\Support\Ui\UiLabel::get($category) }}</option>@endforeach</select></label>
                    <label class="text-sm">Date *<input type="date" name="expense_date" required value="{{ old('expense_date', now()->toDateString()) }}" class="mt-1 w-full"></label>
                    <label class="text-sm">Véhicule facultatif<select name="vehicle_id" class="mt-1 w-full"><option value="">Aucun</option>@foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}" @selected(old('vehicle_id') == $vehicle->id)>{{ $vehicle->registration_number }}</option>@endforeach</select></label>
                    <label class="text-sm">Contrat facultatif<select name="rental_contract_id" class="mt-1 w-full"><option value="">Aucun</option>@foreach($contracts as $contract)<option value="{{ $contract->id }}" @selected(old('rental_contract_id') == $contract->id)>{{ $contract->contract_number }}</option>@endforeach</select></label>
                    <label class="text-sm">Fournisseur<input name="supplier" value="{{ old('supplier') }}" class="mt-1 w-full"></label>
                    <label class="text-sm md:col-span-2">Description *<input name="description" required value="{{ old('description') }}" class="mt-1 w-full"><x-input-error :messages="$errors->get('description')" /></label>
                    <label class="text-sm">Montant (MAD) *<input name="amount" inputmode="decimal" required value="{{ old('amount') }}" class="mt-1 w-full"><x-input-error :messages="$errors->get('amount')" /></label>
                    <label class="text-sm">Taxe indicative (MAD)<input name="tax_amount" inputmode="decimal" value="{{ old('tax_amount', '0.00') }}" class="mt-1 w-full"></label>
                    <input type="hidden" name="currency" value="MAD">
                    <div class="self-end"><button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Enregistrer le brouillon</button></div>
                </form>
            </section>
        @endif

        @if($expenses !== null)
            <form method="GET" class="grid gap-3 rounded-xl bg-white p-4 sm:grid-cols-3">
                <input name="expense_q" value="{{ request('expense_q') }}" placeholder="Numéro, description ou fournisseur" aria-label="Recherche de dépense">
                <select name="expense_status" aria-label="Statut de dépense"><option value="">Tous les statuts</option>@foreach(['draft','approved','rejected'] as $status)<option value="{{ $status }}" @selected(request('expense_status') === $status)>{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select>
                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Filtrer les dépenses</button>
            </form>

            <section class="rounded-xl bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold">Dépenses</h2><x-result-count :paginator="$expenses" /></div>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-slate-500"><th class="p-3">Numéro</th><th class="p-3">Catégorie</th><th class="p-3">Montant</th><th class="p-3">Référence</th><th class="p-3">Statut</th><th class="p-3">Décision</th><th class="p-3"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            @forelse($expenses as $expense)
                                <tr class="border-t align-top">
                                    <td class="p-3">{{ $expense->expense_number }}</td>
                                    <td class="p-3">{{ App\Support\Ui\UiLabel::get($expense->category) }}</td>
                                    <td class="p-3">{{ App\Support\Ui\UiLabel::money($expense->amount, $expense->currency) }}</td>
                                    <td class="p-3">{{ $expense->maintenanceOrder?->maintenance_number ?? $expense->rentalContract?->contract_number ?? $expense->vehicle?->registration_number ?? '—' }}</td>
                                    <td class="p-3"><x-status-badge :value="$expense->status" /></td>
                                    <td class="max-w-xs p-3 text-xs text-slate-600">@if($expense->status === 'rejected'){{ $expense->rejection_reason }}<br>{{ $expense->rejector?->name }} · {{ App\Support\Ui\UiLabel::dateTime($expense->rejected_at) }}@else—@endif</td>
                                    <td class="p-3">
                                        <div class="space-y-3">
                                            @if($expense->status === 'draft' && auth()->user()->hasPermission('expense.approve'))
                                                <form method="POST" action="{{ route('finance.expenses.approve', $expense) }}" onsubmit="return confirm('Approuver cette dépense ?')">@csrf<button type="submit" class="text-indigo-700 underline">Approuver</button></form>
                                            @endif
                                            @if($expense->status === 'draft' && auth()->user()->hasPermission('expense.reject'))
                                                <form method="POST" action="{{ route('finance.expenses.reject', $expense) }}" class="min-w-64 space-y-2" onsubmit="return confirm('Rejeter cette dépense ?')">
                                                    @csrf
                                                    <label class="sr-only" for="reason-{{ $expense->id }}">Motif du rejet</label>
                                                    <textarea id="reason-{{ $expense->id }}" name="reason" required maxlength="2000" rows="2" placeholder="Motif obligatoire" class="w-full text-sm"></textarea>
                                                    <x-input-error :messages="$errors->get('reason')" />
                                                    <button type="submit" class="text-rose-700 underline">Rejeter</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="p-8"><x-empty-state title="Aucune dépense" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $expenses->links() }}</div>
            </section>
        @endif
    </div>
</x-app-layout>
