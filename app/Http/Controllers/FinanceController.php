<?php

namespace App\Http\Controllers;

use App\Actions\Finance\AllocatePaymentToInvoice;
use App\Actions\Finance\ApproveExpense;
use App\Actions\Finance\CloseRentalContract;
use App\Actions\Finance\CreateExpense;
use App\Actions\Finance\CreateInvoiceFromReturnedContract;
use App\Actions\Finance\IssueInvoice;
use App\Actions\Finance\PostPayment;
use App\Actions\Finance\RecordDepositReceipt;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\RefundDeposit;
use App\Actions\Finance\RetainDeposit;
use App\Actions\Finance\ReversePayment;
use App\Actions\Finance\VoidInvoice;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RentalContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(Request $request): View
    {
        $this->permit($request, 'invoice.view');
        $agency = $request->user()->agency_id;
        $scope = fn ($query) => $query->when($agency, fn ($builder) => $builder->where('agency_id', $agency));

        return view('finance.index', [
            'invoices' => $scope(Invoice::query())->latest()->paginate(15, ['*'], 'invoices'),
            'payments' => $scope(Payment::query())->latest()->limit(20)->get(),
            'deposits' => $scope(DepositTransaction::query())->latest('occurred_at')->limit(20)->get(),
            'expenses' => $scope(Expense::query())->latest('expense_date')->limit(20)->get(),
        ]);
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $this->permit($request, 'invoice.view');

        return view('finance.show', ['invoice' => $invoice->load(['lines', 'rentalContract', 'allocations.payment'])]);
    }

    public function createInvoice(Request $request, RentalContract $contract, CreateInvoiceFromReturnedContract $action): RedirectResponse
    {
        $this->permit($request, 'invoice.create');
        $data = $request->validate(['tenant_id' => ['prohibited'], 'tax_mode' => ['nullable', 'in:none,inclusive,exclusive'], 'tax_rate' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,4})?$/']]);
        $invoice = $action->handle($contract, $request->user()->id, $data['tax_mode'] ?? 'none', $data['tax_rate'] ?? '0.0000');

        return redirect()->route('finance.invoices.show', $invoice)->with('status', 'Facture brouillon créée.');
    }

    public function issue(Request $request, Invoice $invoice, IssueInvoice $action): RedirectResponse
    {
        $this->permit($request, 'invoice.issue');
        $data = $request->validate(['due_at' => ['nullable', 'date']]);
        $action->handle($invoice, $request->user()->id, $data['due_at'] ?? null);

        return back()->with('status', 'Facture émise et figée.');
    }

    public function void(Request $request, Invoice $invoice, VoidInvoice $action): RedirectResponse
    {
        $this->permit($request, 'invoice.void');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $action->handle($invoice, $data['reason']);

        return redirect()->route('finance.index')->with('status', 'Facture annulée sans suppression.');
    }

    public function recordPayment(Request $request, RecordPayment $action): RedirectResponse
    {
        $this->permit($request, 'payment.create');
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['required', 'integer'], 'rental_contract_id' => ['nullable', 'integer'], 'customer_id' => ['required', 'integer'], 'payment_method' => ['required', 'in:cash,card,bank_transfer,cheque,other'], 'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'currency' => ['nullable', 'size:3'], 'idempotency_key' => ['required', 'string', 'max:120'], 'external_reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string'], 'card_number' => ['prohibited'], 'pan' => ['prohibited'], 'cvv' => ['prohibited']]);
        $action->handle($data, $request->user()->id);

        return back()->with('status', 'Paiement enregistré.');
    }

    public function allocate(Request $request, Payment $payment, Invoice $invoice, AllocatePaymentToInvoice $action): RedirectResponse
    {
        $this->permit($request, 'payment.allocate');
        $data = $request->validate(['amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/']]);
        $action->handle($payment, $invoice, $data['amount']);

        return back()->with('status', 'Paiement alloué.');
    }

    public function post(Request $request, Payment $payment, PostPayment $action): RedirectResponse
    {
        $this->permit($request, 'payment.post');
        $action->handle($payment, $request->user()->id);

        return back()->with('status', 'Paiement comptabilisé.');
    }

    public function reverse(Request $request, Payment $payment, ReversePayment $action): RedirectResponse
    {
        $this->permit($request, 'payment.reverse');
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:120'], 'reason' => ['required', 'string', 'max:1000']]);
        $action->handle($payment, $data['idempotency_key'], $data['reason'], $request->user()->id);

        return back()->with('status', 'Paiement contrepassé.');
    }

    public function receiveDeposit(Request $request, RentalContract $contract, RecordDepositReceipt $action): RedirectResponse
    {
        $this->permit($request, 'deposit.create');
        $data = $this->depositData($request);
        $action->handle($contract, $data['amount'], $data['idempotency_key'], $request->user()->id);

        return back()->with('status', 'Caution reçue.');
    }

    public function retainDeposit(Request $request, RentalContract $contract, RetainDeposit $action): RedirectResponse
    {
        $this->permit($request, 'deposit.create');
        $data = $this->depositData($request, true);
        $action->handle($contract, $data['amount'], $data['idempotency_key'], $data['reason'], $request->user()->id);

        return back()->with('status', 'Retenue de caution enregistrée.');
    }

    public function refundDeposit(Request $request, RentalContract $contract, RefundDeposit $action): RedirectResponse
    {
        $this->permit($request, 'deposit.create');
        $data = $this->depositData($request);
        $action->handle($contract, $data['amount'], $data['idempotency_key'], $request->user()->id, $data['reason'] ?? null);

        return back()->with('status', 'Remboursement de caution enregistré.');
    }

    public function storeExpense(Request $request, CreateExpense $action): RedirectResponse
    {
        $this->permit($request, 'expense.create');
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['required', 'integer'], 'vehicle_id' => ['nullable', 'integer'], 'rental_contract_id' => ['nullable', 'integer'], 'category' => ['required', 'in:maintenance,insurance,fuel,cleaning,administration,other'], 'description' => ['required', 'string'], 'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'tax_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'], 'currency' => ['nullable', 'size:3'], 'expense_date' => ['required', 'date'], 'supplier' => ['nullable', 'string', 'max:255']]);
        $action->handle($data, $request->user()->id);

        return back()->with('status', 'Dépense brouillon créée.');
    }

    public function approveExpense(Request $request, Expense $expense, ApproveExpense $action): RedirectResponse
    {
        $this->permit($request, 'expense.approve');
        $action->handle($expense, $request->user()->id);

        return back()->with('status', 'Dépense approuvée.');
    }

    public function close(Request $request, RentalContract $contract, CloseRentalContract $action): RedirectResponse
    {
        $this->permit($request, 'contract.close');
        $action->handle($contract, $request->user()->id);

        return back()->with('status', 'Contrat clôturé financièrement.');
    }

    private function permit(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
        $subject = $request->route()?->parameter('contract') ?? $request->route()?->parameter('invoice') ?? $request->route()?->parameter('payment') ?? $request->route()?->parameter('expense');
        abort_if($request->user()->agency_id && $subject && $subject->agency_id !== $request->user()->agency_id, 403);
    }

    private function depositData(Request $request, bool $reasonRequired = false): array
    {
        return $request->validate(['tenant_id' => ['prohibited'], 'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'idempotency_key' => ['required', 'string', 'max:120'], 'reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'max:1000']]);
    }
}
