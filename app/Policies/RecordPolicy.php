<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Rental;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\CompanyAccess;
use App\Support\OperationalAccess;
use App\Support\WorkshopAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class RecordPolicy
{
    public function view(User $user, Model $record): bool
    {
        return $this->allowed($user, $record, 'view');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->allowed($user, $record, 'manage');
    }

    private function allowed(User $user, Model $record, string $action): bool
    {
        $scoped = CompanyAccess::scope($record->newQuery(), $user)->whereKey($record->getKey())->exists();
        if ($user->is_active && $user->hasRole('driver') && $scoped) {
            if ($record instanceof Rental && (int) $record->driver_user_id === $user->id) {
                return true;
            }
            if ($record instanceof Document && $record->entity_type === 'rental' && OperationalAccess::rentals($user)->where('driver_user_id', $user->id)->whereKey($record->entity_id)->exists()) {
                return true;
            }
        }
        if ($record instanceof WorkOrder && ! $user->can('work_orders.'.$action)) {
            return $user->is_active && $user->can('work_orders.'.$action.'_assigned') && WorkshopAccess::query($user, $action)->whereKey($record->id)->exists();
        }
        $domain = Str::plural(Str::snake(class_basename($record)));

        return $user->is_active && ($user->is_platform_admin || ($user->can($domain.'.'.$action) && CompanyAccess::scope($record->newQuery(), $user)->whereKey($record->getKey())->exists()));
    }
}
