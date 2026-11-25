<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class AccountSessions
{
    public static function revoke(User $user): void
    {
        $user->tokens()->delete();
        $user->forceFill(['auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
    }

    public static function login(Request $r, User $user): void
    {
        Auth::guard('web')->login($user);
        $r->session()->regenerate();
        $r->session()->put('auth_version', (int) $user->auth_version);
    }
}
