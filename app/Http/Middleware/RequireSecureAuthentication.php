<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSecureAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(config('drivecore.require_https') && ! $request->isSecure(), 426, 'HTTPS is required for authentication.');

        return $next($request);
    }
}
