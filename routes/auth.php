<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::post('locale', LocaleController::class)->middleware('throttle:60,1,locale')->name('locale.update');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1,password-reset-request')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:10,1,password-reset')
        ->name('password.store');
});

Route::middleware(['auth', 'active.account'])->group(function () {
    Route::get('security/challenge', [AccountSecurityController::class, 'challenge'])->name('security.challenge');
    Route::post('security/challenge', [AccountSecurityController::class, 'verify'])->middleware('throttle:5,1,mfa-challenge')->name('security.verify');
    Route::get('profile/security', [AccountSecurityController::class, 'index'])->name('security.index');
    Route::middleware(['password.confirm', 'throttle:5,1,mfa-management'])->group(function () {
        Route::post('profile/security/prepare', [AccountSecurityController::class, 'prepare'])->name('security.prepare');
        Route::post('profile/security/enable', [AccountSecurityController::class, 'enroll'])->name('security.enroll');
        Route::delete('profile/security/mfa', [AccountSecurityController::class, 'disable'])->name('security.disable');
        Route::delete('profile/security/session', [AccountSecurityController::class, 'revoke'])->name('security.revoke');
    });
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1,email-verify'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1,email-resend')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:5,1,password-confirm');

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
