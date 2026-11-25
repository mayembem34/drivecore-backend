<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'contact_name', 'company_name', 'customer_type', 'email', 'phone', 'source', 'stage', 'vehicle_requirements', 'expected_start_date', 'expected_days', 'estimated_value_minor', 'assigned_to_user_id', 'next_follow_up_at', 'lost_reason', 'customer_id', 'quotation_id', 'notes'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'expected_start_date' => 'immutable_datetime', 'expected_days' => 'integer', 'estimated_value_minor' => 'integer', 'assigned_to_user_id' => 'integer', 'next_follow_up_at' => 'immutable_datetime', 'customer_id' => 'integer', 'quotation_id' => 'integer'];
    }
}
