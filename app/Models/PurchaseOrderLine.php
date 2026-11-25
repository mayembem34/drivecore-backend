<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    protected $fillable = ['purchase_order_id', 'part_id', 'quantity', 'unit_cost_minor', 'line_total_minor'];

    protected function casts(): array
    {
        return ['purchase_order_id' => 'integer', 'part_id' => 'integer', 'quantity' => 'integer', 'unit_cost_minor' => 'integer', 'line_total_minor' => 'integer'];
    }
}
