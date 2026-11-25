<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordRotated
{
    public function handle(Request $r, Closure $next): Response
    {
        $user = $r->user();
        if ($r->hasSession() && ! ($user->currentAccessToken() instanceof PersonalAccessToken)) {
            abort_unless((int) $r->session()->get('auth_version', 0) === (int) $user->auth_version, 401, 'Please sign in again.');
        }
        abort_if($user->must_change_password && ! $r->is('api/v1/auth/me', 'api/v1/auth/logout', 'api/v1/auth/change-password'), 403, 'Change your password before continuing.');

        return $next($r);
    }
}
