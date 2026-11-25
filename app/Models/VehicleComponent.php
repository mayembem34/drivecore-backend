<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleComponent extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'vehicle_id', 'component_type', 'serial_number', 'brand_model', 'position', 'installed_at', 'installed_odometer', 'warranty_expires_at', 'status', 'replaced_at', 'notes'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'vehicle_id' => 'integer', 'installed_at' => 'immutable_datetime', 'installed_odometer' => 'integer', 'warranty_expires_at' => 'immutable_datetime', 'replaced_at' => 'immutable_datetime'];
    }
}
