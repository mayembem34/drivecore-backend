<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManagedUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $company = $this->companies->firstWhere('id', $request->integer('company_id'));

        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'is_active' => $this->is_active, 'roles' => $this->roles->pluck('name'), 'company_id' => $company?->id, 'branch_id' => $company?->pivot->branch_id];
    }
}
