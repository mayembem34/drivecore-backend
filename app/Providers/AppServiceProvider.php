<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Quotation;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\RecordPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::before(fn (User $user, string $ability) => $user->is_active && $user->is_platform_admin ? true : null);
        foreach ([Quotation::class, Branch::class, Customer::class, Vehicle::class, Reservation::class, Rental::class, Document::class] as $model) {
            Gate::policy($model, RecordPolicy::class);
        }
        RateLimiter::for('login', fn (Request $request) => [Limit::perMinute(5)->by(mb_strtolower(is_string($request->input('email')) ? $request->input('email') : '').'|'.$request->ip()), Limit::perMinute(30)->by($request->ip())]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?? $request->ip()));
    }
}
