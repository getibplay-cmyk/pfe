<?php

use App\Http\Controllers\AgencyController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\ChangeRequiredPasswordController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DamageReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlatformDashboardController;
use App\Http\Controllers\PlatformTenantController;
use App\Http\Controllers\PricingRuleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RentalContractController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationExportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantUserController;
use App\Http\Controllers\VehicleBlockController;
use App\Http\Controllers\VehicleCategoryController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleInspectionController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'tenant', 'password.changed'])
    ->name('dashboard');

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();

        return response()->json(['status' => 'ok', 'application' => 'ok', 'database' => 'ok']);
    } catch (Throwable) {
        Log::warning('Health check database unavailable.');

        return response()->json(['status' => 'error', 'application' => 'ok', 'database' => 'error'], 503);
    }
})->name('health');

Route::middleware(['auth', 'active.account'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/password/change-required', [ChangeRequiredPasswordController::class, 'edit'])->name('password.change-required');
    Route::put('/password/change-required', [ChangeRequiredPasswordController::class, 'update'])->name('password.change-required.update');
});

Route::middleware(['auth', 'tenant', 'password.changed'])->group(function () {
    Route::get('/tenant', [TenantController::class, 'show'])->name('tenant.show');
    Route::patch('/tenant', [TenantController::class, 'update'])->name('tenant.update');
    Route::resource('agencies', AgencyController::class);
    Route::resource('users', TenantUserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::post('/users/{user}/reset-password', [TenantUserController::class, 'resetPassword'])->name('users.reset-password');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::resource('roles', RoleController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::get('/role-delegations', [RoleController::class, 'delegations'])->name('roles.delegations');
    Route::put('/role-delegations/{agency}', [RoleController::class, 'updateDelegations'])->name('roles.delegations.update');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::patch('/notifications/{notification}/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::resource('vehicle-categories', VehicleCategoryController::class)->except('show');
    Route::resource('vehicles', VehicleController::class)->except('destroy');
    Route::post('/vehicles/{vehicle}/status', [VehicleController::class, 'changeStatus'])->name('vehicles.status');
    Route::get('/vehicle-blocks', [VehicleBlockController::class, 'index'])->name('vehicle-blocks.index');
    Route::get('/vehicle-blocks/create', [VehicleBlockController::class, 'create'])->name('vehicle-blocks.create');
    Route::post('/vehicle-blocks', [VehicleBlockController::class, 'store'])->name('vehicle-blocks.store');
    Route::post('/vehicle-blocks/{block}/release', [VehicleBlockController::class, 'release'])->name('vehicle-blocks.release');
    Route::post('/vehicle-blocks/{block}/cancel', [VehicleBlockController::class, 'cancel'])->name('vehicle-blocks.cancel');
    Route::resource('customers', CustomerController::class);
    Route::post('/customers/{customer}/verify', [CustomerController::class, 'verify'])->name('customers.verify');
    Route::post('/customers/{customer}/reject-verification', [CustomerController::class, 'reject'])->name('customers.reject-verification');
    Route::post('/customers/{customerId}/restore', [CustomerController::class, 'restore'])->whereNumber('customerId')->name('customers.restore');
    Route::resource('pricing-rules', PricingRuleController::class)->except(['show', 'destroy']);
    Route::get('/availability', AvailabilityController::class)->name('availability.index');
    Route::get('/reservations/export', ReservationExportController::class)->name('reservations.export');
    Route::resource('reservations', ReservationController::class)->except('destroy');
    Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])->name('reservations.confirm');
    Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::get('/contracts', [RentalContractController::class, 'index'])->name('contracts.index');
    Route::get('/contracts/{contract}', [RentalContractController::class, 'show'])->name('contracts.show');
    Route::post('/reservations/{reservation}/contract', [RentalContractController::class, 'store'])->name('contracts.store');
    Route::post('/contracts/{contract}/versions', [RentalContractController::class, 'version'])->name('contracts.versions.store');
    Route::post('/contracts/{contract}/version-document', [RentalContractController::class, 'versionDocument'])->name('contracts.version-document.store');
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
    Route::post('/finance/deposits/{deposit}/reverse', [FinanceController::class, 'reverseDeposit'])->name('finance.deposits.reverse');
    Route::post('/finance/expenses', [FinanceController::class, 'storeExpense'])->name('finance.expenses.store');
    Route::post('/finance/expenses/{expense}/approve', [FinanceController::class, 'approveExpense'])->name('finance.expenses.approve');
    Route::post('/finance/expenses/{expense}/reject', [FinanceController::class, 'rejectExpense'])->name('finance.expenses.reject');
    Route::post('/contracts/{contract}/close', [FinanceController::class, 'close'])->name('finance.contracts.close');
    Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::get('/maintenance/create', [MaintenanceController::class, 'create'])->name('maintenance.create');
    Route::post('/maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');
    Route::get('/maintenance/{maintenance}/edit', [MaintenanceController::class, 'edit'])->name('maintenance.edit');
    Route::put('/maintenance/{maintenance}', [MaintenanceController::class, 'update'])->name('maintenance.update');
    Route::get('/maintenance/{maintenance}/reschedule', [MaintenanceController::class, 'editSchedule'])->name('maintenance.reschedule.edit');
    Route::patch('/maintenance/{maintenance}/reschedule', [MaintenanceController::class, 'reschedule'])->name('maintenance.reschedule');
    Route::get('/maintenance/{maintenance}', [MaintenanceController::class, 'show'])->name('maintenance.show');
    Route::post('/maintenance/{maintenance}/approve', [MaintenanceController::class, 'approve'])->name('maintenance.approve');
    Route::post('/maintenance/{maintenance}/start', [MaintenanceController::class, 'start'])->name('maintenance.start');
    Route::post('/maintenance/{maintenance}/complete', [MaintenanceController::class, 'complete'])->name('maintenance.complete');
    Route::post('/maintenance/{maintenance}/cancel', [MaintenanceController::class, 'cancel'])->name('maintenance.cancel');
    Route::get('/insurance', [InsuranceController::class, 'index'])->name('insurance.index');
    Route::post('/insurance/companies', [InsuranceController::class, 'storeCompany'])->name('insurance.companies.store');
    Route::get('/insurance/companies/{company}', [InsuranceController::class, 'showCompany'])->name('insurance.companies.show');
    Route::get('/insurance/companies/{company}/edit', [InsuranceController::class, 'editCompany'])->name('insurance.companies.edit');
    Route::put('/insurance/companies/{company}', [InsuranceController::class, 'updateCompany'])->name('insurance.companies.update');
    Route::post('/insurance/companies/{company}/deactivate', [InsuranceController::class, 'deactivateCompany'])->name('insurance.companies.deactivate');
    Route::post('/insurance/companies/{company}/reactivate', [InsuranceController::class, 'reactivateCompany'])->name('insurance.companies.reactivate');
    Route::get('/insurance/policies/create', [InsuranceController::class, 'createPolicy'])->name('insurance.policies.create');
    Route::post('/insurance/policies', [InsuranceController::class, 'storePolicy'])->name('insurance.policies.store');
    Route::get('/insurance/policies/{policy}/edit', [InsuranceController::class, 'editPolicy'])->name('insurance.policies.edit');
    Route::put('/insurance/policies/{policy}', [InsuranceController::class, 'updatePolicy'])->name('insurance.policies.update');
    Route::get('/insurance/policies/{policy}', [InsuranceController::class, 'showPolicy'])->name('insurance.policies.show');
    Route::post('/insurance/policies/{policy}/activate', [InsuranceController::class, 'activatePolicy'])->name('insurance.policies.activate');
    Route::post('/insurance/policies/{policy}/cancel', [InsuranceController::class, 'cancelPolicy'])->name('insurance.policies.cancel');
    Route::get('/insurance/policies/{policy}/renew', [InsuranceController::class, 'createRenewal'])->name('insurance.policies.renew.create');
    Route::post('/insurance/policies/{policy}/renew', [InsuranceController::class, 'renewPolicy'])->name('insurance.policies.renew');
    Route::post('/insurance/policies/{policy}/coverages', [InsuranceController::class, 'storeCoverage'])->name('insurance.coverages.store');
    Route::get('/insurance/policies/{policy}/coverages/{coverage}/edit', [InsuranceController::class, 'editCoverage'])->name('insurance.coverages.edit');
    Route::put('/insurance/policies/{policy}/coverages/{coverage}', [InsuranceController::class, 'updateCoverage'])->name('insurance.coverages.update');
    Route::delete('/insurance/policies/{policy}/coverages/{coverage}', [InsuranceController::class, 'archiveCoverage'])->name('insurance.coverages.archive');
    Route::get('/insurance/claims/create', [InsuranceController::class, 'createClaim'])->name('insurance.claims.create');
    Route::post('/insurance/claims', [InsuranceController::class, 'storeClaim'])->name('insurance.claims.store');
    Route::get('/insurance/claims/{claim}', [InsuranceController::class, 'showClaim'])->name('insurance.claims.show');
    Route::post('/insurance/claims/{claim}/submit', [InsuranceController::class, 'submit'])->name('insurance.claims.submit');
    Route::post('/insurance/claims/{claim}/review', [InsuranceController::class, 'review'])->name('insurance.claims.review');
    Route::post('/insurance/claims/{claim}/approve', [InsuranceController::class, 'approve'])->name('insurance.claims.approve');
    Route::post('/insurance/claims/{claim}/reject', [InsuranceController::class, 'reject'])->name('insurance.claims.reject');
    Route::post('/insurance/claims/{claim}/settle', [InsuranceController::class, 'settle'])->name('insurance.claims.settle');
    Route::post('/insurance/claims/{claim}/close', [InsuranceController::class, 'close'])->name('insurance.claims.close');
    Route::get('/customers/{customer}/identity', [CustomerController::class, 'identity'])->name('customers.identity');
    Route::post('/customers/{customer}/drivers', [DriverController::class, 'store'])->name('customers.drivers.store');
    Route::get('/drivers/{driver}', [DriverController::class, 'show'])->name('drivers.show');
    Route::get('/drivers/{driver}/edit', [DriverController::class, 'edit'])->name('drivers.edit');
    Route::put('/drivers/{driver}', [DriverController::class, 'update'])->name('drivers.update');
    Route::post('/drivers/{driver}/verify', [DriverController::class, 'verify'])->name('drivers.verify');
    Route::post('/drivers/{driver}/reject-verification', [DriverController::class, 'reject'])->name('drivers.reject-verification');
    Route::delete('/drivers/{driver}', [DriverController::class, 'destroy'])->name('drivers.destroy');
    Route::post('/drivers/{driverId}/restore', [DriverController::class, 'restore'])->whereNumber('driverId')->name('drivers.restore');
    Route::get('/drivers/{driver}/licence', [DriverController::class, 'licence'])->name('drivers.licence');
    Route::post('/vehicles/{vehicle}/documents', [DocumentController::class, 'storeForVehicle'])->name('vehicles.documents.store');
    Route::post('/customers/{customer}/documents', [DocumentController::class, 'storeForCustomer'])->name('customers.documents.store');
    Route::post('/drivers/{driver}/documents', [DocumentController::class, 'storeForDriver'])->name('drivers.documents.store');
    Route::post('/inspections/{inspection}/documents', [DocumentController::class, 'storeForInspection'])->name('inspections.documents.store');
    Route::post('/damages/{damage}/documents', [DocumentController::class, 'storeForDamage'])->name('damages.documents.store');
    Route::post('/maintenance/{maintenance}/documents', [DocumentController::class, 'storeForMaintenance'])->name('maintenance.documents.store');
    Route::post('/insurance/policies/{policy}/documents', [DocumentController::class, 'storeForInsurancePolicy'])->name('insurance.policies.documents.store');
    Route::post('/insurance/claims/{claim}/documents', [DocumentController::class, 'storeForInsuranceClaim'])->name('insurance.claims.documents.store');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('/documents/{document}/versions', [DocumentController::class, 'addVersion'])->name('documents.versions.store');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('/reports/export', ReportExportController::class)->name('reports.export');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
});

Route::prefix('platform')->name('platform.')->middleware(['auth', 'platform'])->group(function () {
    Route::get('/dashboard', PlatformDashboardController::class)->name('dashboard');
    Route::resource('tenants', PlatformTenantController::class)->except('destroy');
    Route::post('/tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend'])->name('tenants.suspend');
    Route::post('/tenants/{tenant}/reactivate', [PlatformTenantController::class, 'reactivate'])->name('tenants.reactivate');
});

require __DIR__.'/auth.php';
