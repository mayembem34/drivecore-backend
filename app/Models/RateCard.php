<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RateCard extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'customer_id', 'name', 'vehicle_category', 'daily_rate_minor', 'weekly_rate_minor', 'monthly_rate_minor', 'chauffeur_daily_minor', 'daily_km_limit', 'excess_km_rate_minor', 'security_deposit_minor', 'late_return_hourly_minor', 'fuel_policy', 'is_active'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'customer_id' => 'integer', 'daily_rate_minor' => 'integer', 'weekly_rate_minor' => 'integer', 'monthly_rate_minor' => 'integer', 'chauffeur_daily_minor' => 'integer', 'daily_km_limit' => 'integer', 'excess_km_rate_minor' => 'integer', 'security_deposit_minor' => 'integer', 'late_return_hourly_minor' => 'integer', 'is_active' => 'boolean'];
    }
}
