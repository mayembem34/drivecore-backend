<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FuelTransaction extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'vehicle_id', 'driver_user_id', 'rental_id', 'expense_id', 'fuel_date', 'station', 'litres', 'amount_minor', 'odometer', 'payment_method', 'km_since_last', 'litres_per_100km', 'cost_per_km_minor', 'is_abnormal'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'vehicle_id' => 'integer', 'driver_user_id' => 'integer', 'rental_id' => 'integer', 'expense_id' => 'integer', 'fuel_date' => 'immutable_datetime', 'litres' => 'float', 'amount_minor' => 'integer', 'odometer' => 'integer', 'km_since_last' => 'integer', 'litres_per_100km' => 'float', 'cost_per_km_minor' => 'integer', 'is_abnormal' => 'boolean'];
    }
}
