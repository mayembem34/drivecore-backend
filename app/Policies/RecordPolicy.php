<?php

namespace App\Policies;

use App\Models\User;
use App\Support\CompanyAccess;
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
        $domain = Str::plural(Str::snake(class_basename($record)));

        return $user->is_active && ($user->is_platform_admin || ($user->can($domain.'.'.$action) && CompanyAccess::scope($record->newQuery(), $user)->whereKey($record->getKey())->exists()));
    }
}
