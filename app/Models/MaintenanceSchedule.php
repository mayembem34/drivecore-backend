<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceSchedule extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'vehicle_id', 'service_name', 'interval_km', 'interval_days', 'last_service_odometer', 'last_service_at', 'is_active'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'vehicle_id' => 'integer', 'interval_km' => 'integer', 'interval_days' => 'integer', 'last_service_odometer' => 'integer', 'last_service_at' => 'immutable_datetime', 'is_active' => 'boolean'];
    }
}
