<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
    protected $fillable = ['applied_at', 'company_id', 'branch_id', 'type', 'entity_type', 'entity_id', 'title', 'requester_id', 'approver_id', 'previous_value', 'requested_value', 'approved_value', 'reason', 'decision_notes', 'status', 'decided_at'];

    protected function casts(): array
    {
        return ['applied_at' => 'immutable_datetime', 'company_id' => 'integer', 'branch_id' => 'integer', 'entity_id' => 'integer', 'requester_id' => 'integer', 'approver_id' => 'integer', 'decided_at' => 'immutable_datetime'];
    }
}
