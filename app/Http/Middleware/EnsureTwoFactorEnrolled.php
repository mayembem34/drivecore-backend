<?php

namespace App\Http\Middleware;

use App\Support\RoleCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorEnrolled
{
    public function handle(Request $r, Closure $next): Response
    {
        abort_if(! $r->user()->two_factor_confirmed_at && RoleCatalog::enforcement($r->user()) && ! $r->is('api/v1/auth/*'), 403, 'Enroll in two-factor authentication before continuing.');

        return $next($r);
    }
}
