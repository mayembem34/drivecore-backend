<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $fillable = ['invoice_id', 'description', 'quantity', 'unit_price_minor', 'line_total_minor', 'sort_order'];

    protected function casts(): array
    {
        return ['invoice_id' => 'integer', 'quantity' => 'integer', 'unit_price_minor' => 'integer', 'line_total_minor' => 'integer', 'sort_order' => 'integer'];
    }
}
