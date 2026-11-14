<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ReadinessController;
use App\Http\Controllers\Api\V1\RentalController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\RequireSecureAuthentication;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');
    Route::post('auth/tokens', [AuthController::class, 'token'])->middleware(['throttle:login', RequireSecureAuthentication::class])->name('auth.tokens');
    Route::middleware(['auth:sanctum', EnsureActiveAccount::class, 'throttle:api'])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'show'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('readiness', ReadinessController::class)->middleware('can:companies.manage')->name('readiness');
        foreach (['companies' => CompanyController::class, 'branches' => BranchController::class] as $domain => $controller) {
            Route::get($domain, [$controller, 'index'])->middleware('can:'.$domain.'.view')->name($domain.'.index');
            Route::post($domain, [$controller, 'store'])->middleware('can:'.$domain.'.manage')->name($domain.'.store');
        }
        foreach (['customers' => CustomerController::class, 'vehicles' => VehicleController::class, 'reservations' => ReservationController::class, 'rentals' => RentalController::class, 'documents' => DocumentController::class] as $domain => $controller) {
            Route::apiResource($domain, $controller)->only(['index', 'show'])->middleware('can:'.$domain.'.view');
            Route::apiResource($domain, $controller)->only(['store'])->middleware('can:'.$domain.'.manage');
        }
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.manage')->name('customers.update');
        Route::patch('vehicles/{vehicle}', [VehicleController::class, 'update'])->middleware('can:vehicles.manage')->name('vehicles.update');
        Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->middleware('can:reservations.manage')->name('reservations.cancel');
        Route::post('rentals/{rental}/{action}', [RentalController::class, 'transition'])->whereIn('action', ['handover', 'return'])->middleware('can:rentals.manage')->name('rentals.transition');
        Route::post('users', [UserController::class, 'store'])->middleware('can:users.manage')->name('users.store');
    });
});
