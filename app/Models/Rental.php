<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rental extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'reservation_id', 'vehicle_id', 'status', 'handed_over_at', 'returned_at', 'handover_odometer', 'return_odometer', 'handover_notes', 'return_notes', 'driver_user_id'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    protected function casts(): array
    {
        return ['driver_user_id' => 'integer', 'handed_over_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime', 'handover_odometer' => 'integer', 'return_odometer' => 'integer'];
    }
}
