<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rental extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'reservation_id', 'vehicle_id', 'status', 'handed_over_at', 'returned_at', 'handover_odometer', 'return_odometer', 'handover_notes', 'return_notes'];

    protected function casts(): array
    {
        return ['handed_over_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime', 'handover_odometer' => 'integer', 'return_odometer' => 'integer'];
    }
}
