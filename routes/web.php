<?php

use App\Http\Controllers\AgencyController;
use App\Http\Controllers\AgencyDistanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\ChangeRequiredPasswordController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DamageReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemandForecastController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FleetReallocationPlanningController;
use App\Http\Controllers\FleetReallocationProposalController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\IntelligenceController;
use App\Http\Controllers\IntelligenceDatasetExportController;
use App\Http\Controllers\IntelligenceDatasetExportManifestController;
use App\Http\Controllers\IntelligenceDatasetSnapshotDownloadController;
use App\Http\Controllers\IntelligenceResultBatchController;
use App\Http\Controllers\J11ContractDemoController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlatformDashboardController;
use App\Http\Controllers\PlatformIntelligenceController;
use App\Http\Controllers\PlatformPlanController;
use App\Http\Controllers\PlatformSaasPaymentController;
use App\Http\Controllers\PlatformStatisticsController;
use App\Http\Controllers\PlatformSubscriptionController;
use App\Http\Controllers\PlatformTenantController;
use App\Http\Controllers\PricingRuleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RentalContractController;
use App\Http\Controllers\RentalUsageAnomalyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationDemandForecastController;
use App\Http\Controllers\ReservationExportController;
use App\Http\Controllers\ReturnDamageAssistantController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantSaasAccountController;
use App\Http\Controllers\TenantUserController;
use App\Http\Controllers\VehicleBlockController;
use App\Http\Controllers\VehicleCategoryController;
use App\Http\Controllers\VehicleColorAssistantController;
use App\Http\Controllers\VehicleColorPredictionController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleDamagePredictionController;
use App\Http\Controllers\VehicleInspectionController;
use App\Http\Controllers\VehiclePlatePredictionController;
use App\Http\Controllers\VehicleRegistrationAssistantController;
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
    Route::get('/tenant/saas-account', TenantSaasAccountController::class)->name('tenant-saas-account.show');
    Route::resource('agencies', AgencyController::class);
    Route::get('/fleet/agency-distances', [AgencyDistanceController::class, 'index'])
        ->name('agency-distances.index');
    Route::post('/fleet/agency-distances', [AgencyDistanceController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('agency-distances.store');
    Route::patch('/fleet/agency-distances/{agencyDistance}', [AgencyDistanceController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('agency-distances.update');
    Route::patch('/fleet/agency-distances/{agencyDistance}/activate', [AgencyDistanceController::class, 'activate'])
        ->middleware('throttle:30,1')
        ->name('agency-distances.activate');
    Route::patch('/fleet/agency-distances/{agencyDistance}/deactivate', [AgencyDistanceController::class, 'deactivate'])
        ->middleware('throttle:30,1')
        ->name('agency-distances.deactivate');
    Route::get('/fleet/reallocation-planning', [FleetReallocationPlanningController::class, 'index'])
        ->name('fleet.reallocation-planning.index');
    Route::post('/fleet/reallocation-planning/runs', [FleetReallocationPlanningController::class, 'store'])
        ->middleware(['tenant.intelligence:fleet_reallocation', 'throttle:fleet-reallocation-planning'])
        ->name('fleet.reallocation-planning.runs.store');
    Route::get('/fleet/reallocation-planning/runs/{run}/status', [FleetReallocationPlanningController::class, 'status'])
        ->middleware('throttle:60,1')
        ->name('fleet.reallocation-planning.runs.status');
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
    Route::post('/vehicles/color-assistant', [VehicleColorAssistantController::class, 'store'])
        ->middleware(['tenant.intelligence:vehicle_color', 'throttle:vehicle-color-v8'])
        ->name('vehicles.color-assistant.store');
    Route::get('/vehicles/color-assistant/{colorPrediction}', [VehicleColorAssistantController::class, 'show'])
        ->whereUuid('colorPrediction')
        ->middleware('throttle:120,1')
        ->name('vehicles.color-assistant.show');
    Route::post('/vehicles/registration-assistant', [VehicleRegistrationAssistantController::class, 'store'])
        ->middleware(['tenant.intelligence:vehicle_plate', 'throttle:vehicle-plate-hybrid'])
        ->name('vehicles.registration-assistant.store');
    Route::get('/vehicles/registration-assistant/{platePrediction}', [VehicleRegistrationAssistantController::class, 'show'])
        ->whereUuid('platePrediction')
        ->middleware('throttle:120,1')
        ->name('vehicles.registration-assistant.show');
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
    Route::post('/reservations/demand-forecast', [ReservationDemandForecastController::class, 'store'])
        ->middleware(['tenant.intelligence:demand_forecast', 'throttle:reservation-demand-forecast'])
        ->name('reservations.demand-forecast.store');
    Route::get('/reservations/demand-forecast/{forecastExecution}', [ReservationDemandForecastController::class, 'show'])
        ->whereUuid('forecastExecution')
        ->middleware('throttle:120,1')
        ->name('reservations.demand-forecast.show');
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
    Route::post('/contracts/{contract}/return-damage-assistant', [ReturnDamageAssistantController::class, 'store'])
        ->middleware(['tenant.intelligence:vehicle_damage', 'throttle:vehicle-damage-v1'])
        ->name('contracts.return-damage-assistant.store');
    Route::get('/contracts/{contract}/return-damage-assistant/{damagePrediction}', [ReturnDamageAssistantController::class, 'show'])
        ->whereUuid('damagePrediction')
        ->middleware('throttle:120,1')
        ->name('contracts.return-damage-assistant.show');
    Route::get('/contracts/{contract}/return-damage-assistant/{damagePrediction}/preview', [ReturnDamageAssistantController::class, 'preview'])
        ->whereUuid('damagePrediction')
        ->middleware('throttle:120,1')
        ->name('contracts.return-damage-assistant.preview');
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
    Route::get('/intelligence/export', IntelligenceDatasetExportController::class)->name('intelligence.export');
    Route::get('/intelligence/exports/{exportRun}/manifest', IntelligenceDatasetExportManifestController::class)
        ->name('intelligence.exports.manifest');
    Route::get('/intelligence/exports/{exportRun}/download', IntelligenceDatasetSnapshotDownloadController::class)
        ->name('intelligence.exports.download');
    Route::get('/intelligence/result-batches', [IntelligenceResultBatchController::class, 'index'])
        ->name('intelligence.result-batches.index');
    Route::post('/intelligence/exports/{exportRun}/result-batches', [IntelligenceResultBatchController::class, 'store'])
        ->name('intelligence.result-batches.store');
    Route::get('/intelligence/result-batches/{resultBatch}/download', [IntelligenceResultBatchController::class, 'download'])
        ->name('intelligence.result-batches.download');
    Route::post('/intelligence/result-batches/{resultBatch}/decisions', [IntelligenceResultBatchController::class, 'decide'])
        ->name('intelligence.result-batches.decisions.store');
    Route::get('/intelligence/demand-forecasts', [DemandForecastController::class, 'index'])
        ->name('intelligence.demand-forecasts.index');
    Route::get('/intelligence/rental-usage-anomalies', [RentalUsageAnomalyController::class, 'index'])
        ->name('intelligence.rental-usage-anomalies.index');
    Route::post('/intelligence/rental-usage-anomalies/runs', [RentalUsageAnomalyController::class, 'storeLatest'])
        ->middleware(['tenant.intelligence:rental_usage_anomaly', 'throttle:rental-usage-anomaly-v1'])
        ->name('intelligence.rental-usage-anomalies.runs.store');
    Route::post('/intelligence/exports/{exportRun}/rental-usage-anomalies', [RentalUsageAnomalyController::class, 'store'])
        ->middleware(['tenant.intelligence:rental_usage_anomaly', 'throttle:rental-usage-anomaly-v1'])
        ->name('intelligence.rental-usage-anomalies.store');
    Route::post('/intelligence/rental-usage-anomalies/contracts/{contract}/reviews', [RentalUsageAnomalyController::class, 'reviewForContract'])
        ->middleware('throttle:rental-usage-anomaly-review')
        ->name('intelligence.rental-usage-anomalies.contract-reviews.store');
    Route::post('/intelligence/rental-usage-anomalies/results/{anomalyResult}/reviews', [RentalUsageAnomalyController::class, 'review'])
        ->middleware('throttle:rental-usage-anomaly-review')
        ->name('intelligence.rental-usage-anomalies.reviews.store');
    Route::get('/intelligence/demand-history/export', [DemandForecastController::class, 'export'])
        ->middleware('tenant.intelligence:demand_forecast')
        ->name('intelligence.demand-history.export');
    Route::get('/intelligence/demand-history/{historyRun}/manifest', [DemandForecastController::class, 'manifest'])
        ->name('intelligence.demand-history.manifest');
    Route::get('/intelligence/demand-history/{historyRun}/download', [DemandForecastController::class, 'download'])
        ->name('intelligence.demand-history.download');
    Route::post('/intelligence/demand-history/{historyRun}/forecasts', [DemandForecastController::class, 'store'])
        ->middleware('tenant.intelligence:demand_forecast')
        ->name('intelligence.demand-forecasts.store');
    Route::post('/intelligence/demand-history/{historyRun}/forecast-executions', [DemandForecastController::class, 'queueExecution'])
        ->middleware('tenant.intelligence:demand_forecast')
        ->name('intelligence.demand-forecast-executions.store');
    Route::get('/intelligence/vehicle-colors', [VehicleColorPredictionController::class, 'index'])
        ->name('intelligence.vehicle-colors.index');
    Route::post('/intelligence/vehicle-colors', [VehicleColorPredictionController::class, 'store'])
        ->middleware(['tenant.intelligence:vehicle_color', 'throttle:vehicle-color-v8'])
        ->name('intelligence.vehicle-colors.store');
    Route::get('/intelligence/vehicle-colors/{colorPrediction}/input', [VehicleColorPredictionController::class, 'input'])
        ->name('intelligence.vehicle-colors.input');
    Route::post('/intelligence/vehicle-colors/{colorPrediction}/reviews', [VehicleColorPredictionController::class, 'review'])
        ->name('intelligence.vehicle-colors.reviews.store');
    Route::get('/intelligence/vehicle-damages', [VehicleDamagePredictionController::class, 'index'])
        ->name('intelligence.vehicle-damages.index');
    Route::post('/intelligence/vehicle-damages', [VehicleDamagePredictionController::class, 'store'])
        ->middleware(['tenant.intelligence:vehicle_damage', 'throttle:vehicle-damage-v1'])
        ->name('intelligence.vehicle-damages.store');
    Route::get('/intelligence/vehicle-damages/{damagePrediction}/input', [VehicleDamagePredictionController::class, 'input'])
        ->name('intelligence.vehicle-damages.input');
    Route::post('/intelligence/vehicle-damages/{damagePrediction}/reviews', [VehicleDamagePredictionController::class, 'review'])
        ->name('intelligence.vehicle-damages.reviews.store');
    Route::get('/intelligence/vehicle-plates', [VehiclePlatePredictionController::class, 'index'])
        ->name('intelligence.vehicle-plates.index');
    Route::post('/intelligence/vehicle-plates', [VehiclePlatePredictionController::class, 'store'])
        ->middleware(['tenant.intelligence:vehicle_plate', 'throttle:vehicle-plate-hybrid'])
        ->name('intelligence.vehicle-plates.store');
    Route::get('/intelligence/vehicle-plates/{platePrediction}/input', [VehiclePlatePredictionController::class, 'input'])
        ->name('intelligence.vehicle-plates.input');
    Route::get('/intelligence/vehicle-plates/{platePrediction}/crop', [VehiclePlatePredictionController::class, 'crop'])
        ->name('intelligence.vehicle-plates.crop');
    Route::post('/intelligence/vehicle-plates/{platePrediction}/reviews', [VehiclePlatePredictionController::class, 'review'])
        ->name('intelligence.vehicle-plates.reviews.store');
    Route::get('/intelligence/fleet-reallocation', [FleetReallocationProposalController::class, 'index'])
        ->name('intelligence.fleet-reallocation.index');
    Route::post('/intelligence/fleet-reallocation/runs', [FleetReallocationProposalController::class, 'queueRun'])
        ->middleware('tenant.intelligence:fleet_reallocation')
        ->name('intelligence.fleet-reallocation.runs.store');
    Route::post('/intelligence/fleet-reallocation', [FleetReallocationProposalController::class, 'store'])
        ->middleware('tenant.intelligence:fleet_reallocation')
        ->name('intelligence.fleet-reallocation.store');
    Route::get('/intelligence/fleet-reallocation/{reallocationProposal}/download', [FleetReallocationProposalController::class, 'download'])
        ->name('intelligence.fleet-reallocation.download');
    Route::post('/intelligence/fleet-reallocation/{reallocationProposal}/decisions', [FleetReallocationProposalController::class, 'decide'])
        ->name('intelligence.fleet-reallocation.decisions.store');
    Route::prefix('/intelligence/contracts-demo')
        ->name('intelligence.contract-demo.')
        ->middleware('intelligence.contract-demo')
        ->group(function () {
            Route::get('/', [J11ContractDemoController::class, 'index'])->name('index');
            Route::post('/fixtures', [J11ContractDemoController::class, 'store'])->name('fixtures.store');
            Route::post('/{record}/decisions', [J11ContractDemoController::class, 'decide'])->name('decisions.store');
        });
    Route::get('/intelligence', IntelligenceController::class)->name('intelligence.index');
});

Route::prefix('platform')->name('platform.')->middleware(['auth', 'platform'])->group(function () {
    Route::get('/dashboard', PlatformDashboardController::class)->name('dashboard');
    Route::get('/statistics', PlatformStatisticsController::class)->name('statistics.index');
    Route::resource('tenants', PlatformTenantController::class)->only(['index', 'create', 'show', 'edit']);
    Route::get('/plans', [PlatformPlanController::class, 'index'])->name('plans.index');
    Route::get('/subscriptions', [PlatformSubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('/tenants/{tenant}/subscriptions/create', [PlatformSubscriptionController::class, 'create'])->name('tenants.subscriptions.create');
    Route::get('/saas-payments', [PlatformSaasPaymentController::class, 'index'])->name('saas-payments.index');
    Route::get('/tenants/{tenant}/saas-payments/create', [PlatformSaasPaymentController::class, 'create'])->name('tenants.saas-payments.create');
    Route::get('/intelligence', [PlatformIntelligenceController::class, 'index'])->name('intelligence.index');

    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/tenants', [PlatformTenantController::class, 'store'])->name('tenants.store');
        Route::match(['put', 'patch'], '/tenants/{tenant}', [PlatformTenantController::class, 'update'])->name('tenants.update');
        Route::post('/tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend'])->name('tenants.suspend');
        Route::post('/tenants/{tenant}/reactivate', [PlatformTenantController::class, 'reactivate'])->name('tenants.reactivate');
        Route::post('/plans', [PlatformPlanController::class, 'store'])->name('plans.store');
        Route::patch('/plans/{plan}', [PlatformPlanController::class, 'update'])->name('plans.update');
        Route::post('/tenants/{tenant}/subscriptions', [PlatformSubscriptionController::class, 'store'])->name('tenants.subscriptions.store');
        Route::patch('/subscriptions/{subscription}/status', [PlatformSubscriptionController::class, 'transition'])->name('subscriptions.transition');
        Route::post('/tenants/{tenant}/subscriptions/{subscription}/saas-payments', [PlatformSaasPaymentController::class, 'store'])->name('tenants.saas-payments.store');
        Route::post('/saas-payments/{payment}/reverse', [PlatformSaasPaymentController::class, 'reverse'])->name('saas-payments.reverse');
        Route::patch('/tenants/{tenant}/intelligence/{capability}', [PlatformIntelligenceController::class, 'update'])->name('intelligence.update');
    });
});

require __DIR__.'/auth.php';
