<?php

use App\Http\Controllers\AgencyController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DamageReportController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\PricingRuleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RentalContractController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantUserController;
use App\Http\Controllers\VehicleCategoryController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleInspectionController;
use App\Models\Expense;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use App\Models\MaintenanceOrder;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::get('/dashboard', function (Request $request) {
    $agencyId = $request->user()->agency_id;
    $vehicleQuery = Vehicle::query()->when($agencyId, fn ($query) => $query->where('agency_id', $agencyId));
    $reservationQuery = fn () => Reservation::query()->when($agencyId, fn ($query) => $query->where('agency_id', $agencyId));
    $todayStart = now(config('reservations.display_timezone'))->startOfDay();
    $todayEnd = $todayStart->addDay();
    $agencyScope = fn ($query) => $query->when($agencyId, fn ($builder) => $builder->where('agency_id', $agencyId));
    $collected = DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')
        ->join('invoices as i', 'i.id', '=', 'a.invoice_id')->where('a.tenant_id', $request->user()->tenant_id)
        ->when($agencyId, fn ($query) => $query->where('i.agency_id', $agencyId))->whereIn('p.status', ['posted', 'reversed'])
        ->selectRaw("COALESCE(SUM(CASE WHEN p.direction = 'incoming' THEN a.amount ELSE -a.amount END), 0) AS amount")->value('amount');
    $depositOutstanding = $agencyScope(RentalContract::query())->sum(DB::raw('deposit_received - deposit_retained - deposit_refunded'));
    $approvedExpenses = $agencyScope(Expense::query())->where('status', 'approved')->sum('amount');

    return view('dashboard', ['kpis' => [
        'Véhicules opérationnels' => (clone $vehicleQuery)->where('operational_status', 'active')->count(),
        'Réservations confirmées' => $reservationQuery()->where('status', 'confirmed')->count(),
        'Départs attendus aujourd’hui' => $reservationQuery()->where('status', 'confirmed')->where('starts_at', '>=', $todayStart)->where('starts_at', '<', $todayEnd)->count(),
        'Expirées ou à traiter' => $reservationQuery()->where(fn ($query) => $query->where('status', 'expired')->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('expires_at', '<=', now())))->count(),
        'Chiffre d’affaires encaissé' => DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits((string) $collected)).' MAD',
        'Factures impayées' => $agencyScope(Invoice::query())->whereIn('status', ['issued', 'partially_paid'])->count(),
        'Cautions à rembourser' => DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits((string) $depositOutstanding)).' MAD',
        'Dépenses approuvées' => DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits((string) $approvedExpenses)).' MAD',
        'Véhicules en maintenance' => (clone $vehicleQuery)->where('operational_status', 'maintenance')->count(),
        'Maintenances proches' => $agencyScope(MaintenanceOrder::query())->whereNotNull('next_due_date')->whereDate('next_due_date', '<=', today()->addDays(30))->count(),
        'Assurances expirant prochainement' => $agencyScope(InsurancePolicy::query())->where('status', 'active')->whereDate('ends_at', '<=', today()->addDays(30))->count(),
    ]]);
})->middleware(['auth', 'tenant'])->name('dashboard');

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();

        return response()->json(['status' => 'ok', 'application' => 'ok', 'database' => 'ok']);
    } catch (Throwable) {
        Log::warning('Health check database unavailable.');

        return response()->json(['status' => 'error', 'application' => 'ok', 'database' => 'error'], 503);
    }
})->name('health');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/tenant', [TenantController::class, 'show'])->name('tenant.show');
    Route::resource('agencies', AgencyController::class)->except('show');
    Route::resource('users', TenantUserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::resource('vehicle-categories', VehicleCategoryController::class)->except('show');
    Route::resource('vehicles', VehicleController::class)->except('destroy');
    Route::post('/vehicles/{vehicle}/status', [VehicleController::class, 'changeStatus'])->name('vehicles.status');
    Route::resource('customers', CustomerController::class)->except('destroy');
    Route::resource('pricing-rules', PricingRuleController::class)->except(['show', 'destroy']);
    Route::get('/availability', AvailabilityController::class)->name('availability.index');
    Route::resource('reservations', ReservationController::class)->except('destroy');
    Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])->name('reservations.confirm');
    Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::get('/contracts', [RentalContractController::class, 'index'])->name('contracts.index');
    Route::get('/contracts/{contract}', [RentalContractController::class, 'show'])->name('contracts.show');
    Route::post('/reservations/{reservation}/contract', [RentalContractController::class, 'store'])->name('contracts.store');
    Route::post('/contracts/{contract}/versions', [RentalContractController::class, 'version'])->name('contracts.versions.store');
    Route::post('/contracts/{contract}/ready', [RentalContractController::class, 'ready'])->name('contracts.ready');
    Route::post('/contracts/{contract}/accept', [RentalContractController::class, 'accept'])->name('contracts.accept');
    Route::post('/contracts/{contract}/departure-inspection', [VehicleInspectionController::class, 'departure'])->name('contracts.departure-inspection');
    Route::post('/contracts/{contract}/activate', [RentalContractController::class, 'activate'])->name('contracts.activate');
    Route::post('/contracts/{contract}/return-inspection', [VehicleInspectionController::class, 'return'])->name('contracts.return-inspection');
    Route::post('/contracts/{contract}/charges', [RentalContractController::class, 'charges'])->name('contracts.charges');
    Route::post('/contracts/{contract}/damages', [DamageReportController::class, 'store'])->name('contracts.damages.store');
    Route::post('/damages/{damage}/review', [DamageReportController::class, 'review'])->name('damages.review');
    Route::post('/contracts/{contract}/returned', [RentalContractController::class, 'returned'])->name('contracts.returned');
    Route::post('/contracts/{contract}/cancel', [RentalContractController::class, 'cancel'])->name('contracts.cancel');
    Route::get('/contracts/{contract}/print', [RentalContractController::class, 'print'])->name('contracts.print');
    Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::get('/finance/invoices/{invoice}', [FinanceController::class, 'show'])->name('finance.invoices.show');
    Route::post('/contracts/{contract}/invoice', [FinanceController::class, 'createInvoice'])->name('finance.invoices.create');
    Route::post('/finance/invoices/{invoice}/issue', [FinanceController::class, 'issue'])->name('finance.invoices.issue');
    Route::post('/finance/invoices/{invoice}/void', [FinanceController::class, 'void'])->name('finance.invoices.void');
    Route::post('/finance/payments', [FinanceController::class, 'recordPayment'])->name('finance.payments.store');
    Route::post('/finance/payments/{payment}/invoices/{invoice}', [FinanceController::class, 'allocate'])->name('finance.allocations.store');
    Route::post('/finance/payments/{payment}/post', [FinanceController::class, 'post'])->name('finance.payments.post');
    Route::post('/finance/payments/{payment}/reverse', [FinanceController::class, 'reverse'])->name('finance.payments.reverse');
    Route::post('/contracts/{contract}/deposit/receive', [FinanceController::class, 'receiveDeposit'])->name('finance.deposits.receive');
    Route::post('/contracts/{contract}/deposit/retain', [FinanceController::class, 'retainDeposit'])->name('finance.deposits.retain');
    Route::post('/contracts/{contract}/deposit/refund', [FinanceController::class, 'refundDeposit'])->name('finance.deposits.refund');
    Route::post('/finance/expenses', [FinanceController::class, 'storeExpense'])->name('finance.expenses.store');
    Route::post('/finance/expenses/{expense}/approve', [FinanceController::class, 'approveExpense'])->name('finance.expenses.approve');
    Route::post('/contracts/{contract}/close', [FinanceController::class, 'close'])->name('finance.contracts.close');
    Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('/maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');
    Route::post('/maintenance/{maintenance}/approve', [MaintenanceController::class, 'approve'])->name('maintenance.approve');
    Route::post('/maintenance/{maintenance}/start', [MaintenanceController::class, 'start'])->name('maintenance.start');
    Route::post('/maintenance/{maintenance}/complete', [MaintenanceController::class, 'complete'])->name('maintenance.complete');
    Route::post('/maintenance/{maintenance}/cancel', [MaintenanceController::class, 'cancel'])->name('maintenance.cancel');
    Route::get('/insurance', [InsuranceController::class, 'index'])->name('insurance.index');
    Route::post('/insurance/companies', [InsuranceController::class, 'storeCompany'])->name('insurance.companies.store');
    Route::post('/insurance/policies', [InsuranceController::class, 'storePolicy'])->name('insurance.policies.store');
    Route::post('/insurance/policies/{policy}/coverages', [InsuranceController::class, 'storeCoverage'])->name('insurance.coverages.store');
    Route::post('/insurance/claims', [InsuranceController::class, 'storeClaim'])->name('insurance.claims.store');
    Route::get('/customers/{customer}/identity', [CustomerController::class, 'identity'])->name('customers.identity');
    Route::post('/customers/{customer}/drivers', [DriverController::class, 'store'])->name('customers.drivers.store');
    Route::post('/vehicles/{vehicle}/documents', [DocumentController::class, 'storeForVehicle'])->name('vehicles.documents.store');
    Route::post('/customers/{customer}/documents', [DocumentController::class, 'storeForCustomer'])->name('customers.documents.store');
    Route::post('/drivers/{driver}/documents', [DocumentController::class, 'storeForDriver'])->name('drivers.documents.store');
    Route::post('/inspections/{inspection}/documents', [DocumentController::class, 'storeForInspection'])->name('inspections.documents.store');
    Route::post('/damages/{damage}/documents', [DocumentController::class, 'storeForDamage'])->name('damages.documents.store');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('/documents/{document}/versions', [DocumentController::class, 'addVersion'])->name('documents.versions.store');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
});

Route::prefix('platform')->name('platform.')->middleware(['auth', 'platform'])->group(function () {
    Route::view('/dashboard', 'platform.dashboard')->name('dashboard');
});

require __DIR__.'/auth.php';
