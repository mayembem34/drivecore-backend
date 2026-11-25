<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = ['vin', 'category', 'fuel_type', 'transmission', 'current_location', 'acquisition_date', 'acquisition_cost_minor', 'financing_type', 'financing_provider', 'financing_monthly_minor', 'financing_end_date', 'current_book_value_minor', 'residual_value_minor', 'insurance_policy_number', 'insurance_provider', 'insurance_expires_at', 'roadworthy_expires_at', 'licence_expires_at', 'company_id', 'branch_id', 'registration', 'make', 'model', 'year', 'status', 'odometer', 'daily_rate_minor', 'functions'];

    public const FINANCIAL_FIELDS = ['acquisition_date', 'acquisition_cost_minor', 'financing_type', 'financing_provider', 'financing_monthly_minor', 'financing_end_date', 'current_book_value_minor', 'residual_value_minor'];

    public function fixedAsset(): HasOne
    {
        return $this->hasOne(FixedAsset::class);
    }

    protected function casts(): array
    {
        return ['year' => 'integer', 'odometer' => 'integer', 'daily_rate_minor' => 'integer', 'functions' => 'array'];
    }
}
