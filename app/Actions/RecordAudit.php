<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class RecordAudit
{
    public static function write(User $user, string $action, Model $subject, ?int $companyId = null, array $metadata = []): void
    {
        AuditEvent::create(['branch_id' => $subject->branch_id, 'metadata' => $metadata === [] ? null : $metadata, 'company_id' => $companyId ?? ($subject instanceof Company ? $subject->id : $subject->company_id), 'user_id' => $user->id, 'action' => $action, 'subject_type' => class_basename($subject), 'subject_id' => $subject->id]);
    }
}
