<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Middleware\RequireSecureAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/v1/auth/login', [AuthController::class, 'login'])->middleware(['throttle:login', RequireSecureAuthentication::class])->name('api.v1.auth.login');

Route::middleware(['throttle:login', RequireSecureAuthentication::class])->group(function (): void {
    Route::get('/api/v1/invitations/preview', [InvitationController::class, 'preview']);
    Route::post('/api/v1/invitations/accept', [InvitationController::class, 'accept']);
    Route::post('/api/v1/auth/forgot-password', [AccountController::class, 'forgot']);
    Route::post('/api/v1/auth/reset-password', [AccountController::class, 'reset']);
});

Route::post('/api/v1/auth/two-factor/challenge', [TwoFactorController::class, 'challenge'])->middleware(['throttle:login', RequireSecureAuthentication::class]);
