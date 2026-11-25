<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

final class RoleCatalog
{
    public const SRS = ['ceo', 'general-manager', 'business-accounts-manager', 'operations-manager', 'maintenance-manager', 'finance-manager', 'credit-control-manager', 'hr-admin-manager', 'mechanic-technician', 'system-administrator'];

    public const PRIVILEGED = ['ceo', 'general-manager', 'finance-manager', 'credit-control-manager', 'system-administrator', 'company-admin', 'finance'];

    public static function all(): array
    {
        return array_merge(self::SRS, ['company-admin', 'operations', 'finance', 'viewer', 'driver']);
    }

    public static function privileged(User $u): bool
    {
        return $u->is_platform_admin || $u->hasAnyRole(self::PRIVILEGED);
    }

    public static function enforcement(User $u): bool
    {
        return self::privileged($u) && $u->companies()->where('require_2fa_for_privileged', true)->exists();
    }

    public static function canAssign(Request $r, ?string $role, ?User $target = null): void
    {
        if ($r->user()->is_platform_admin || $r->user()->can('users.manage')) {
            return;
        } abort_unless($r->user()->can('users.manage_non_privileged'), 403);
        abort_if(in_array($role, self::PRIVILEGED, true) || ($target && self::privileged($target)), 403, 'Only a system administrator can change privileged accounts.');
    }
}
