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
use App\Actions\Finance\ReverseDepositTransaction;
use App\Actions\Finance\ReversePayment;
use App\Actions\Finance\VoidInvoice;
use App\Http\Requests\Finance\AllocatePaymentRequest;
use App\Http\Requests\Finance\CreateInvoiceRequest;
use App\Http\Requests\Finance\DepositMovementRequest;
use App\Http\Requests\Finance\ReverseDepositRequest;
use App\Http\Requests\Finance\ReversePaymentRequest;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Http\Requests\Finance\StorePaymentRequest;
use App\Models\Agency;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Models\Vehicle;
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
            'invoices' => $scope(Invoice::with('rentalContract'))
                ->when($request->string('invoice_status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('invoice_status')))
                ->when($request->string('q')->isNotEmpty(), fn ($query) => $query->where('invoice_number', 'ilike', '%'.$request->string('q').'%'))
                ->latest()->paginate(15, ['*'], 'invoices')->withQueryString(),
            'payments' => $scope(Payment::with(['rentalContract', 'allocations.invoice']))->latest()->limit(20)->get(),
            'deposits' => $scope(DepositTransaction::with('rentalContract'))->latest('occurred_at')->limit(20)->get(),
            'expenses' => $scope(Expense::with(['vehicle', 'rentalContract', 'maintenanceOrder']))->latest('expense_date')->limit(20)->get(),
            'agencies' => Agency::query()->when($agency, fn ($query) => $query->whereKey($agency))->orderBy('name')->get(),
            'vehicles' => $request->user()->hasPermission('expense.create')
                ? $scope(Vehicle::query())->orderBy('registration_number')->limit(200)->get()
                : collect(),
            'contracts' => $request->user()->hasPermission('expense.create')
                ? $scope(RentalContract::with('customer'))->latest()->limit(100)->get()
                : collect(),
        ]);
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $this->permit($request, 'invoice.view');

        $invoice->load(['lines', 'customer', 'rentalContract.depositTransactions', 'allocations.payment']);
        $payments = Payment::with('allocations')
            ->where('agency_id', $invoice->agency_id)
            ->where('customer_id', $invoice->customer_id)
            ->where('currency', $invoice->currency)
            ->where('direction', 'incoming')
            ->whereIn('status', ['pending', 'posted', 'reversed'])
            ->latest()
            ->get();

        return view('finance.show', ['invoice' => $invoice, 'payments' => $payments]);
    }

    public function createInvoice(CreateInvoiceRequest $request, RentalContract $contract, CreateInvoiceFromReturnedContract $action): RedirectResponse
    {
        $data = $request->validated();
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

    public function recordPayment(StorePaymentRequest $request, RecordPayment $action): RedirectResponse
    {
        $action->handle($request->validated(), $request->user()->id);

        return back()->with('status', 'Paiement enregistré.');
    }

    public function allocate(AllocatePaymentRequest $request, Payment $payment, Invoice $invoice, AllocatePaymentToInvoice $action): RedirectResponse
    {
        $action->handle($payment, $invoice, $request->validated('amount'));

        return back()->with('status', 'Paiement alloué.');
    }

    public function post(Request $request, Payment $payment, PostPayment $action): RedirectResponse
    {
        $this->permit($request, 'payment.post');
        $action->handle($payment, $request->user()->id);

        return back()->with('status', 'Paiement comptabilisé.');
    }

    public function reverse(ReversePaymentRequest $request, Payment $payment, ReversePayment $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($payment, $data['idempotency_key'], $data['reason'], $request->user()->id);

        return back()->with('status', 'Paiement contrepassé.');
    }

    public function receiveDeposit(DepositMovementRequest $request, RentalContract $contract, RecordDepositReceipt $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($contract, $data['amount'], $data['idempotency_key'], $request->user()->id);

        return back()->with('status', 'Caution reçue.');
    }

    public function retainDeposit(DepositMovementRequest $request, RentalContract $contract, RetainDeposit $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($contract, $data['amount'], $data['idempotency_key'], $data['reason'], $request->user()->id);

        return back()->with('status', 'Retenue de caution enregistrée.');
    }

    public function refundDeposit(DepositMovementRequest $request, RentalContract $contract, RefundDeposit $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($contract, $data['amount'], $data['idempotency_key'], $request->user()->id, $data['reason'] ?? null);

        return back()->with('status', 'Remboursement de caution enregistré.');
    }

    public function reverseDeposit(ReverseDepositRequest $request, DepositTransaction $deposit, ReverseDepositTransaction $action): RedirectResponse
    {
        $data = $request->validated();
        $action->handle($deposit, $data['idempotency_key'], $data['reason'], $request->user()->id);

        return back()->with('status', 'Mouvement de caution contrepassé sans réécriture de l’historique.');
    }

    public function storeExpense(StoreExpenseRequest $request, CreateExpense $action): RedirectResponse
    {
        $action->handle($request->validated(), $request->user()->id);

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
}
