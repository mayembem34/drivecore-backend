<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\RequireSecureAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/v1/auth/login', [AuthController::class, 'login'])->middleware(['throttle:login', RequireSecureAuthentication::class])->name('api.v1.auth.login');
