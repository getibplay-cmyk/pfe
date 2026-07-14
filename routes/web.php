<?php

use App\Http\Controllers\AgencyController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DamageReportController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\PricingRuleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RentalContractController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantUserController;
use App\Http\Controllers\VehicleCategoryController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleInspectionController;
use App\Models\Reservation;
use App\Models\Vehicle;
use Illuminate\Http\Request;
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

    return view('dashboard', ['kpis' => [
        'Véhicules opérationnels' => (clone $vehicleQuery)->where('operational_status', 'active')->count(),
        'Réservations confirmées' => $reservationQuery()->where('status', 'confirmed')->count(),
        'Départs attendus aujourd’hui' => $reservationQuery()->where('status', 'confirmed')->where('starts_at', '>=', $todayStart)->where('starts_at', '<', $todayEnd)->count(),
        'Expirées ou à traiter' => $reservationQuery()->where(fn ($query) => $query->where('status', 'expired')->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('expires_at', '<=', now())))->count(),
    ]]);
})->middleware(['auth', 'tenant'])->name('dashboard');

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();

        return response()->json(['status' => 'ok', 'application' => 'ok', 'database' => 'ok']);
    } catch (Throwable $exception) {
        report($exception);

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
