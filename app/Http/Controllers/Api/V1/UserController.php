<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManagedUserResource;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private const ROLES = ['company-admin', 'operations', 'viewer', 'driver'];

    private function workspace(Request $request): Company
    {
        $request->validate(['company_id' => ['required', 'integer', 'min:1']]);
        $company = CompanyAccess::company($request->user(), $request->integer('company_id'));
        abort_unless($request->user()->is_platform_admin || $company->pivot->branch_id === null, 403);

        return $company;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $company = $this->workspace($request);
        $data = $request->validate(['branch_id' => ['sometimes', 'integer', 'min:1'], 'role' => ['sometimes', Rule::in(self::ROLES)], 'is_active' => ['sometimes', 'boolean'], 'q' => ['sometimes', 'nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        if (isset($data['branch_id'])) {
            CompanyAccess::branch($request->user(), $company->id, $data['branch_id']);
        }
        $query = User::query()->whereHas('companies', function (Builder $query) use ($company, $data): void {
            $query->where('companies.id', $company->id);
            if (isset($data['branch_id'])) {
                $query->where('company_user.branch_id', $data['branch_id']);
            }
        });
        if (! $request->user()->is_platform_admin) {
            $query->where('is_platform_admin', false);
        }
        if (isset($data['role'])) {
            $query->role($data['role']);
        }
        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', $data['is_active']);
        }
        if (isset($data['q']) && trim($data['q']) !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(fn (Builder $part) => $part->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])->orWhereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
        }

        return ManagedUserResource::collection($query->with(['roles', 'companies' => fn ($query) => $query->where('companies.id', $company->id)])->orderBy('id')->paginate(25)->withQueryString());
    }

    public function roles(Request $request): JsonResponse
    {
        $this->workspace($request);

        return response()->json(['data' => Role::where('guard_name', 'web')->whereIn('name', self::ROLES)->with('permissions')->orderBy('name')->get()->map(fn (Role $role) => ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')])]);
    }

    public function update(Request $request, int $user): ManagedUserResource
    {
        $company = $this->workspace($request);
        $data = $request->validate(['branch_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'role' => ['sometimes', Rule::in(self::ROLES)], 'is_active' => ['sometimes', 'boolean']]);
        if (isset($data['branch_id'])) {
            CompanyAccess::branch($request->user(), $company->id, $data['branch_id']);
        }

        return DB::transaction(function () use ($request, $user, $company, $data): ManagedUserResource {
            $record = User::whereHas('companies', fn (Builder $query) => $query->where('companies.id', $company->id))->lockForUpdate()->findOrFail($user);
            abort_if(! $request->user()->is_platform_admin && $record->is_platform_admin, 404);
            abort_if($record->id === $request->user()->id && $data !== [], 409, 'Use another administrator to change your own access.');
            abort_if(! $request->user()->is_platform_admin && (isset($data['role']) || array_key_exists('is_active', $data)) && $record->companies()->count() !== 1, 409, 'A platform administrator must change global access for a multi-company user.');
            if (isset($data['role'])) {
                $record->syncRoles($data['role']);
            }
            if (array_key_exists('is_active', $data)) {
                $record->is_active = $data['is_active'];
                $record->save();
                if (! $record->is_active) {
                    $record->tokens()->delete();
                }
            }
            if (array_key_exists('branch_id', $data)) {
                $record->companies()->updateExistingPivot($company->id, ['branch_id' => $data['branch_id']]);
            }
            RecordAudit::write($request->user(), 'user.updated', $record, $company->id);

            return new ManagedUserResource($record->load(['roles', 'companies' => fn ($query) => $query->where('companies.id', $company->id)]));
        });
    }

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
