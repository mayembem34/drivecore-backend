<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAsset extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'vehicle_id', 'asset_code', 'name', 'category', 'acquisition_cost_minor', 'residual_value_minor', 'capitalisation_date', 'useful_life_months', 'depreciation_method', 'accumulated_depreciation_minor', 'status', 'disposal_type', 'disposal_date', 'disposal_proceeds_minor', 'disposal_gain_loss_minor', 'disposal_approval_id'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'vehicle_id' => 'integer', 'acquisition_cost_minor' => 'integer', 'residual_value_minor' => 'integer', 'capitalisation_date' => 'immutable_datetime', 'useful_life_months' => 'integer', 'accumulated_depreciation_minor' => 'integer', 'disposal_date' => 'immutable_datetime', 'disposal_proceeds_minor' => 'integer', 'disposal_gain_loss_minor' => 'integer', 'disposal_approval_id' => 'integer'];
    }

    public function netBookValue(): int
    {
        return $this->status === 'disposed' ? 0 : max(0, $this->acquisition_cost_minor - $this->accumulated_depreciation_minor);
    }
}
