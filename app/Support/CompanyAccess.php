<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class CompanyAccess
{
    public static function scope(Builder $query, User $user): Builder
    {
        if ($user->is_platform_admin) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->whereRaw('1 = 0');
            foreach ($user->companies()->get() as $company) {
                $query->orWhere(function (Builder $part) use ($company): void {
                    $part->where('company_id', $company->id);
                    if ($company->pivot->branch_id !== null && ! ($part->getModel() instanceof Supplier)) {
                        $part->where($part->getModel() instanceof Branch ? 'id' : 'branch_id', $company->pivot->branch_id);
                    }
                });
            }
        });
    }

    public static function company(User $user, int $id): Company
    {
        return ($user->is_platform_admin ? Company::query() : $user->companies())->findOrFail($id);
    }

    public static function record(User $user, string $model, int $id): Model
    {
        return self::scope($model::query(), $user)->findOrFail($id);
    }

    public static function branch(User $user, int $companyId, int $branchId): void
    {
        $company = self::company($user, $companyId);
        if (! $user->is_platform_admin && $company->pivot->branch_id !== null) {
            abort_unless((int) $company->pivot->branch_id === $branchId, 404);
        }
        abort_unless(Branch::where('company_id', $companyId)->whereKey($branchId)->exists(), 404);
    }
}
