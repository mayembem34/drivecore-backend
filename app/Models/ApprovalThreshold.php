<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalThreshold extends Model
{
    protected $fillable = ['company_id', 'type', 'threshold_minor', 'threshold_pct', 'approver_role', 'is_active'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'threshold_minor' => 'integer', 'threshold_pct' => 'float', 'is_active' => 'boolean'];
    }
}
