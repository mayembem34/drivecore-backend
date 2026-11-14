<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\CompanyAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function store(Request $request): UserResource
    {
        $data = $request->validate(['company_id' => ['required', 'integer'], 'branch_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email', 'lowercase'], 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()], 'role' => ['required', Rule::in(['company-admin', 'operations', 'viewer', 'driver'])]]);
        $company = CompanyAccess::company($request->user(), $data['company_id']);
        abort_unless($request->user()->is_platform_admin || $company->pivot->branch_id === null, 403);
        if (isset($data['branch_id'])) {
            CompanyAccess::branch($request->user(), $company->id, $data['branch_id']);
        }

        return DB::transaction(function () use ($request, $data, $company): UserResource {
            $user = User::create(collect($data)->only(['name', 'email', 'password'])->all());
            $company->users()->attach($user->id, ['branch_id' => $data['branch_id'] ?? null]);
            $user->assignRole($data['role']);
            RecordAudit::write($request->user(), 'user.created', $company);

            return new UserResource($user);
        });
    }
}
