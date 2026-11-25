<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleIncident extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'vehicle_id', 'rental_id', 'incident_type', 'severity', 'occurred_at', 'location', 'driver_name', 'insurance_claim_number', 'estimated_cost_minor', 'description', 'status', 'work_order_id'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'vehicle_id' => 'integer', 'rental_id' => 'integer', 'occurred_at' => 'immutable_datetime', 'estimated_cost_minor' => 'integer', 'work_order_id' => 'integer'];
    }
}
