<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Support\AccountSessions;
use App\Support\CompanyAccess;
use App\Support\DirectoryQuery;
use App\Support\RoleCatalog;
use App\Support\TwoFactorAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    private function admin(Request $r, int $companyId): Company
    {
        $c = CompanyAccess::company($r->user(), $companyId);
        abort_unless($r->user()->is_platform_admin || $c->pivot->branch_id === null, 403);

        return $c;
    }

    private function payload(Invitation $i): array
    {
        return $i->only(['id', 'company_id', 'branch_id', 'email', 'role', 'expires_at', 'accepted_at', 'revoked_at', 'created_at']) + ['branch_name' => $i->branch?->name, 'invited_by_name' => $i->inviter?->name, 'status' => $i->status()];
    }

    public function index(Request $r): JsonResponse
    {
        $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'status' => ['sometimes', Rule::in(['pending', 'accepted', 'revoked', 'expired'])]]);
        $c = $this->admin($r, $r->integer('company_id'));
        $q = DirectoryQuery::apply($r, Invitation::where('company_id', $c->id), ['email'], workspace: false);
        if ($r->filled('status')) {
            match ($r->input('status')) {
                'accepted' => $q->whereNotNull('accepted_at'),'revoked' => $q->whereNull('accepted_at')->whereNotNull('revoked_at'),'expired' => $q->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<', now()),default => $q->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>=', now())
            };
        } $page = $q->with(['branch', 'inviter'])->orderByDesc('id')->paginate(25)->withQueryString();
        $page->through(fn (Invitation $i) => $this->payload($i));

        return response()->json($page);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'email' => ['required', 'email', 'max:255', 'lowercase'], 'role' => ['required', Rule::in(RoleCatalog::all())]]);
        RoleCatalog::canAssign($r, $d['role']);
        $c = $this->admin($r, $d['company_id']);
        if (isset($d['branch_id'])) {
            CompanyAccess::branch($r->user(), $c->id, $d['branch_id']);
        }

        return DB::transaction(function () use ($r, $d, $c): JsonResponse {
            Company::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $user = User::where('email', $d['email'])->first();
            if (($user && $user->companies()->whereKey($c->id)->exists()) || Invitation::where('company_id', $c->id)->where('email', $d['email'])->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['email' => 'This account is already a member or has a pending invitation.']);
            }
            $raw = bin2hex(random_bytes(32));
            $i = Invitation::create($d + ['invited_by' => $r->user()->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addHours(72)]);
            Notification::route('mail', $i->email)->notify(new AccountInvitation($raw, $c->name));
            RecordAudit::write($r->user(), 'invitation.created', $i);

            return response()->json(['data' => $this->payload($i->load(['branch', 'inviter']))], 201);
        }, 3);
    }

    public function transition(Request $r, int $id, string $action): JsonResponse
    {
        $i = Invitation::findOrFail($id);
        RoleCatalog::canAssign($r, $i->role);
        $c = $this->admin($r, $i->company_id);

        return DB::transaction(function () use ($r, $i, $c, $action): JsonResponse {
            $i = Invitation::lockForUpdate()->findOrFail($i->id);
            abort_if($i->accepted_at || $i->revoked_at, 409);
            if ($action === 'resend') {
                DB::table('invitation_token_history')->insert(['token_hash' => $i->token_hash, 'invalidated_at' => now()]);
                $raw = bin2hex(random_bytes(32));
                $i->update(['token_hash' => hash('sha256', $raw), 'expires_at' => now()->addHours(72)]);
                Notification::route('mail', $i->email)->notify(new AccountInvitation($raw, $c->name));
            } else {
                $i->update(['revoked_at' => now()]);
            } RecordAudit::write($r->user(), 'invitation.'.$action, $i);

            return response()->json(['data' => $this->payload($i->load(['branch', 'inviter']))]);
        }, 3);
    }

    private function lookup(string $token, bool $lock = false): Invitation
    {
        $hash = hash('sha256', $token);
        $q = Invitation::where('token_hash', $hash);
        $i = ($lock ? $q->lockForUpdate() : $q)->first();
        if (! $i) {
            abort(DB::table('invitation_token_history')->where('token_hash', $hash)->exists() ? 410 : 404);
        } abort_unless($i->status() === 'pending', 410, 'This invitation is no longer valid.');

        return $i;
    }

    public function preview(Request $r): JsonResponse
    {
        $d = $r->validate(['token' => ['required', 'string', 'max:255']]);
        $i = $this->lookup($d['token'])->load(['company', 'branch']);

        return response()->json(['data' => ['email' => $i->email, 'company_name' => $i->company->name, 'branch_name' => $i->branch?->name, 'role' => $i->role, 'expires_at' => $i->expires_at, 'account_exists' => User::where('email', $i->email)->exists()]]);
    }

    public function accept(Request $r): JsonResponse
    {
        $d = $r->validate(['token' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);

        return DB::transaction(function () use ($r, $d): JsonResponse {
            $i = $this->lookup($d['token'], true);
            $user = User::where('email', $i->email)->lockForUpdate()->first();
            if ($user) {
                abort_if($user->getRoleNames()->isNotEmpty() && ! $user->hasRole($i->role), 409, 'An existing multi-company account must retain its current role.');
                abort_unless($user->is_active, 403);
                if (! Hash::check($d['password'], $user->password)) {
                    throw ValidationException::withMessages(['password' => 'The existing account password is incorrect.']);
                }
            } else {
                $details = $r->validate(['name' => ['required', 'string', 'max:120'], 'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()]]);
                $user = User::create($details + ['email' => $i->email]);
            }
            $user->companies()->syncWithoutDetaching([$i->company_id => ['branch_id' => $i->branch_id]]);
            $user->assignRole($i->role);
            $i->update(['accepted_at' => now()]);
            RecordAudit::write($user, 'invitation.accepted', $i);
            if ($user->two_factor_confirmed_at) {
                return TwoFactorAuth::challenge($user, 'session');
            }
            AccountSessions::login($r, $user);

            return response()->json(['data' => (new UserResource($user))->resolve($r), 'company_id' => $i->company_id]);
        }, 3);
    }
}
