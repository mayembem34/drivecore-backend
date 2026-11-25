<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

final class WorkshopAccess
{
    public static function query(User $u, string $action = 'view'): Builder
    {
        $q = CompanyAccess::scope(WorkOrder::query(), $u);
        if (! $u->can('work_orders.'.$action)) {
            abort_unless($u->can('work_orders.'.$action.'_assigned'), 403);
            $branches = $u->companies()->whereNotNull('company_user.branch_id')->pluck('company_user.branch_id');
            $q->where(fn (Builder $x) => $x->where('assigned_to_user_id', $u->id)->orWhereIn('branch_id', $branches));
        }

        return $q;
    }
}
