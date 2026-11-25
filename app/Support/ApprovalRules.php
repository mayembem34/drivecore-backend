<?php

namespace App\Support;

use App\Models\ApprovalRequest;
use App\Models\ApprovalThreshold;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class ApprovalRules
{
    public static function needed(int $company, string $type, int|float|string $value): bool
    {
        $t = ApprovalThreshold::where('company_id', $company)->where('type', $type)->where('is_active', true)->first();
        if (! $t) {
            return false;
        } $limit = $type === 'discount' ? $t->threshold_pct : $t->threshold_minor;

        return $limit === null || ! is_numeric($value) || (float) $value > (float) $limit;
    }

    public static function check(User $user, Model $entity, string $type, string $entityType, int|float|string $value, ?int $id): void
    {
        if (! self::needed($entity->company_id, $type, $value)) {
            return;
        } $a = ApprovalRequest::whereKey($id)->lockForUpdate()->first();
        abort_unless($a && $a->company_id === $entity->company_id && $a->branch_id === $entity->branch_id && $a->type === $type && $a->entity_type === $entityType && $a->entity_id === $entity->id && $a->status === 'approved' && $a->requester_id !== $a->approver_id && $a->applied_at === null && $a->approved_value === (string) $value, 409, 'An independent unused approval for this exact change is required.');
        $a->update(['applied_at' => now()]);
    }
}
