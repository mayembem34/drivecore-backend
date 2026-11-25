<?php

namespace App\Http\Resources;

use App\Support\RoleCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['two_factor_enabled' => $this->two_factor_confirmed_at !== null, 'two_factor_enrollment_required' => $this->two_factor_confirmed_at === null && RoleCatalog::enforcement($this->resource), 'must_change_password' => (bool) $this->must_change_password, 'id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'is_platform_admin' => $this->is_platform_admin, 'roles' => $this->getRoleNames(), 'permissions' => $this->getAllPermissions()->pluck('name'), 'companies' => $this->companies()->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'branch_id' => $c->pivot->branch_id])];
    }
}
