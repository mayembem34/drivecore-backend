<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Support\AccountSessions;
use App\Support\CompanyAccess;
use App\Support\RoleCatalog;
use App\Support\TwoFactorAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function setup(Request $r): JsonResponse
    {
        return DB::transaction(function () use ($r): JsonResponse {
            $u = User::lockForUpdate()->findOrFail($r->user()->id);
            abort_if($u->two_factor_confirmed_at !== null, 409, 'Two-factor authentication is already enabled.');
            $secret = TwoFactorAuth::secret();
            $u->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null])->save();

            return response()->json(['data' => ['secret' => $secret, 'otpauth_uri' => 'otpauth://totp/'.rawurlencode('DriveCore:'.$u->email).'?secret='.$secret.'&issuer=DriveCore&algorithm=SHA1&digits=6&period=30']]);
        }, 3);
    }

    public function confirm(Request $r): JsonResponse
    {
        $d = $r->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']]);

        return DB::transaction(function () use ($r, $d): JsonResponse {
            $u = User::lockForUpdate()->findOrFail($r->user()->id);
            abort_if($u->two_factor_confirmed_at !== null || ! $u->two_factor_secret, 409);
            if (! TwoFactorAuth::verify($u->two_factor_secret, $d['code'])) {
                throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
            } $codes = [];
            for ($i = 0; $i < 8; $i++) {
                $codes[] = bin2hex(random_bytes(10));
            } $u->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => array_map(fn (string $code) => hash('sha256', $code), $codes)])->save();
            AccountSessions::revoke($u);
            if ($r->hasSession()) {
                $r->session()->put('auth_version', $u->auth_version);
            } RecordAudit::write($u, 'security.two_factor_enabled', $u, $u->companies()->orderBy('companies.id')->value('companies.id'));

            return response()->json(['data' => ['two_factor_enabled' => true, 'recovery_codes' => $codes]]);
        }, 3);
    }

    public function challenge(Request $r): JsonResponse
    {
        $d = $r->validate(['challenge_token' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'required_without:recovery_code', 'string', 'regex:/^\d{6}$/D'], 'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:100']]);
        $result = DB::transaction(function () use ($d): array {
            $c = TwoFactorChallenge::where('token_hash', hash('sha256', $d['challenge_token']))->lockForUpdate()->first();
            if (! $c || $c->used_at || $c->expires_at->isPast() || $c->attempts >= 5) {
                return [null, null];
            } $u = User::lockForUpdate()->findOrFail($c->user_id);
            if (! $u->is_active || ! $u->two_factor_confirmed_at || $u->auth_version !== $c->auth_version) {
                $c->update(['used_at' => now()]);

                return [null, null];
            } $c->increment('attempts');
            $valid = false;
            if (! empty($d['recovery_code'])) {
                $hash = hash('sha256', $d['recovery_code']);
                $codes = $u->two_factor_recovery_codes ?? [];
                foreach ($codes as $i => $stored) {
                    if (hash_equals($stored, $hash)) {
                        unset($codes[$i]);
                        $u->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                        $valid = true;
                        break;
                    }
                }
            } elseif (! empty($d['code'])) {
                $valid = TwoFactorAuth::verify($u->two_factor_secret, $d['code']);
            }
            if (! $valid) {
                return [null, null];
            } $c->update(['used_at' => now()]);

            return [$u, $c];
        }, 3);
        [$u,$challenge] = $result;
        if (! $u) {
            throw ValidationException::withMessages(['code' => 'The authentication challenge or code is invalid or expired.']);
        }
        if ($challenge->mode === 'token') {
            return response()->json(['data' => ['token' => $u->createToken($challenge->device_name, ['*'], now()->addDays(7))->plainTextToken, 'token_type' => 'Bearer', 'expires_in' => 604800, 'user' => (new UserResource($u))->resolve($r)]]);
        } AccountSessions::login($r, $u);

        return response()->json(['data' => (new UserResource($u))->resolve($r)]);
    }

    public function disable(Request $r): JsonResponse
    {
        $d = $r->validate(['password' => ['required', 'string'], 'code' => ['required', 'string']]);

        return DB::transaction(function () use ($r, $d): JsonResponse {
            $u = User::lockForUpdate()->findOrFail($r->user()->id);
            abort_if(RoleCatalog::enforcement($u), 409, 'Your company requires two-factor authentication.');
            if (! Hash::check($d['password'], $u->password) || ! $u->two_factor_secret || ! TwoFactorAuth::verify($u->two_factor_secret, $d['code'])) {
                throw ValidationException::withMessages(['code' => 'The password or authentication code is invalid.']);
            } $this->clear($u);
            if ($r->hasSession()) {
                $r->session()->put('auth_version', $u->auth_version);
            } RecordAudit::write($u, 'security.two_factor_disabled', $u, $u->companies()->orderBy('companies.id')->value('companies.id'));

            return response()->json(['data' => ['two_factor_enabled' => false]]);
        }, 3);
    }

    private function clear(User $u): void
    {
        $u->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        AccountSessions::revoke($u);
    }

    public function reset(Request $r, int $id): JsonResponse
    {
        abort_unless($r->user()->is_platform_admin || $r->user()->hasRole('system-administrator'), 403);
        abort_if($r->user()->id === $id, 409, 'Use your own two-factor security settings.');
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1']]);
        $company = CompanyAccess::company($r->user(), $d['company_id']);
        abort_unless($r->user()->is_platform_admin || $company->pivot->branch_id === null, 403);

        return DB::transaction(function () use ($r, $id, $company): JsonResponse {
            $u = User::whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->lockForUpdate()->findOrFail($id);
            abort_if($u->is_platform_admin && ! $r->user()->is_platform_admin, 403);
            abort_if(! $r->user()->is_platform_admin && $u->companies()->count() > 1, 409, 'A platform administrator must reset a multi-company account.');
            $this->clear($u);
            RecordAudit::write($r->user(), 'security.two_factor_reset', $u, $company->id);

            return response()->json(['data' => ['two_factor_enabled' => false]]);
        }, 3);
    }
}
