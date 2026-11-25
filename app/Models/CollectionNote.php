<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionNote extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'customer_id', 'created_by', 'note_type', 'promised_amount_minor', 'promised_date', 'status', 'notes'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'customer_id' => 'integer', 'created_by' => 'integer', 'promised_amount_minor' => 'integer', 'promised_date' => 'immutable_datetime'];
    }
}
