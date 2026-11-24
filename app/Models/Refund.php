<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'invoice_id', 'recorded_by', 'amount_minor', 'reason', 'refunded_at'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'invoice_id' => 'integer', 'amount_minor' => 'integer', 'refunded_at' => 'immutable_datetime'];
    }
}
