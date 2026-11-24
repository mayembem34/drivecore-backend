<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'vehicle_id', 'recorded_by', 'category', 'description', 'amount_minor', 'incurred_at'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'vehicle_id' => 'integer', 'amount_minor' => 'integer', 'incurred_at' => 'immutable_datetime'];
    }
}
