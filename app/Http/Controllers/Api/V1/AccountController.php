<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Jobs\SendPasswordReset;
use App\Models\User;
use App\Support\AccountSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function profile(Request $r): UserResource
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['prohibited']]);
        $r->user()->update($d);

        return new UserResource($r->user());
    }

    public function changePassword(Request $r): UserResource
    {
        $d = $r->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()], 'revoke_other_sessions' => ['sometimes', 'boolean']]);
        $user = $r->user();
        if (! Hash::check($d['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        } if (Hash::check($d['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a different password.']);
        }
        DB::transaction(function () use ($r, $user, $d): void {
            $user->forceFill(['password' => $d['password'], 'must_change_password' => false])->save();
            if ($d['revoke_other_sessions'] ?? false) {
                AccountSessions::revoke($user);
                if ($r->hasSession()) {
                    $r->session()->put('auth_version', (int) $user->auth_version);
                }
            }
        });

        return new UserResource($user);
    }

    public function forgot(Request $r): JsonResponse
    {
        $d = $r->validate(['email' => ['required', 'email', 'max:255']]);
        SendPasswordReset::dispatch(mb_strtolower($d['email']));

        return response()->json(['message' => 'If an account matches that email, a reset link has been sent.']);
    }

    public function reset(Request $r): JsonResponse
    {
        $d = $r->validate(['email' => ['required', 'email'], 'token' => ['required', 'string', 'max:255'], 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $status = Password::reset($d, function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $locked = User::lockForUpdate()->findOrFail($user->id);
                $locked->forceFill(['password' => $password, 'must_change_password' => false])->save();
                AccountSessions::revoke($locked);
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid or expired.']);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
