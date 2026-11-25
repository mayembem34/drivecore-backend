<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleInspection extends Model
{
    protected $fillable = ['signature_data_url', 'company_id', 'branch_id', 'rental_id', 'vehicle_id', 'recorded_by', 'type', 'odometer', 'fuel_level_pct', 'exterior_ok', 'interior_ok', 'tyres_ok', 'spare_tyre_ok', 'tools_ok', 'accessories_ok', 'documents_ok', 'damage_notes', 'customer_acknowledged_by', 'customer_acknowledged_at'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'rental_id' => 'integer', 'vehicle_id' => 'integer', 'recorded_by' => 'integer', 'odometer' => 'integer', 'fuel_level_pct' => 'integer', 'exterior_ok' => 'boolean', 'interior_ok' => 'boolean', 'tyres_ok' => 'boolean', 'spare_tyre_ok' => 'boolean', 'tools_ok' => 'boolean', 'accessories_ok' => 'boolean', 'documents_ok' => 'boolean', 'customer_acknowledged_at' => 'immutable_datetime'];
    }
}
