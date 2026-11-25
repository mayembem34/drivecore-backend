<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'part_id', 'recorded_by', 'quantity', 'unit_cost_minor', 'reason', 'work_order_id', 'purchase_order_id'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'part_id' => 'integer', 'quantity' => 'integer', 'unit_cost_minor' => 'integer', 'work_order_id' => 'integer', 'purchase_order_id' => 'integer'];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
