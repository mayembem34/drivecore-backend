<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanySettingsController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\QuotationController;
use App\Http\Controllers\Api\V1\ReadinessController;
use App\Http\Controllers\Api\V1\RecordPdfController;
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
        foreach (['customers' => CustomerController::class, 'vehicles' => VehicleController::class, 'reservations' => ReservationController::class] as $domain => $controller) {
            Route::apiResource($domain, $controller)->only(['index', 'show'])->middleware('can:'.$domain.'.view');
            Route::apiResource($domain, $controller)->only(['store'])->middleware('can:'.$domain.'.manage');
        }
        Route::apiResource('rentals', RentalController::class)->only(['index', 'show', 'store']);
        Route::apiResource('documents', DocumentController::class)->only(['index', 'show', 'store']);
        Route::post('rentals/{rental}/extend', [RentalController::class, 'extend'])->middleware('can:rentals.manage')->name('rentals.extend');
        Route::post('rentals/{rental}/assign-driver', [RentalController::class, 'assignDriver'])->middleware('can:rentals.manage')->name('rentals.assign-driver');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.manage')->name('customers.update');
        Route::patch('vehicles/{vehicle}', [VehicleController::class, 'update'])->middleware('can:vehicles.manage')->name('vehicles.update');
        Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->middleware('can:reservations.manage')->name('reservations.cancel');
        Route::post('rentals/{rental}/{action}', [RentalController::class, 'transition'])->whereIn('action', ['handover', 'return'])->name('rentals.transition');
        Route::get('{domain}/{id}/pdf', RecordPdfController::class)->whereIn('domain', ['quotations', 'reservations', 'rentals', 'invoices'])->whereNumber('id');
        Route::get('companies/{company}/settings', [CompanySettingsController::class, 'show'])->middleware('can:companies.view');
        Route::patch('companies/{company}/settings', [CompanySettingsController::class, 'update'])->middleware('can:settings.manage');
        Route::apiResource('quotations', QuotationController::class)->only(['index', 'show'])->middleware('can:quotations.view');
        Route::post('quotations', [QuotationController::class, 'store'])->middleware('can:quotations.manage');
        Route::post('quotations/{quotation}/{decision}', [QuotationController::class, 'decide'])->whereIn('decision', ['approve-discount', 'reject-discount'])->middleware('can:quotations.approve_discount');
        Route::post('quotations/{quotation}/cancel', [QuotationController::class, 'cancel'])->middleware('can:quotations.manage');
        Route::post('quotations/{quotation}/convert', [QuotationController::class, 'convert'])->middleware(['can:quotations.manage', 'can:reservations.manage']);
        Route::get('invoices/ageing', [InvoiceController::class, 'ageing'])->middleware('can:invoices.view');
        Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show'])->middleware('can:invoices.view');
        Route::apiResource('invoices', InvoiceController::class)->only(['store', 'update'])->middleware('can:invoices.manage');
        Route::post('invoices/{invoice}/{action}', [InvoiceController::class, 'transition'])->whereIn('action', ['issue', 'void'])->middleware('can:invoices.manage');
        Route::post('invoices/{invoice}/{kind}', [InvoiceController::class, 'settlement'])->whereIn('kind', ['payments', 'refunds'])->middleware('can:payments.manage');
        Route::get('{domain}', [FinanceController::class, 'index'])->whereIn('domain', ['payments', 'refunds'])->middleware('can:invoices.view');
        Route::get('expenses', [FinanceController::class, 'index'])->defaults('domain', 'expenses')->middleware('can:expenses.view');
        Route::post('expenses', [FinanceController::class, 'storeExpense'])->middleware('can:expenses.manage');
        Route::get('gl/accounts', [FinanceController::class, 'accounts'])->middleware('can:gl.view');
        Route::get('gl/journal-entries', [FinanceController::class, 'journals'])->middleware('can:gl.view');
        Route::get('users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
        Route::get('roles', [UserController::class, 'roles'])->middleware('can:users.view')->name('roles.index');
        Route::patch('users/{user}', [UserController::class, 'update'])->middleware('can:users.manage')->name('users.update');
        Route::post('users', [UserController::class, 'store'])->middleware('can:users.manage')->name('users.store');
    });
});
