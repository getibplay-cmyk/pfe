<?php

use App\Http\Controllers\AgencyController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantUserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
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
});

Route::prefix('platform')->name('platform.')->middleware(['auth', 'platform'])->group(function () {
    Route::view('/dashboard', 'platform.dashboard')->name('dashboard');
});

require __DIR__.'/auth.php';
