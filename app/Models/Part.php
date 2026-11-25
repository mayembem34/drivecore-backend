<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Part extends Model
{
    protected $fillable = ['location', 'company_id', 'branch_id', 'sku', 'name', 'unit_cost_minor', 'min_stock'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'unit_cost_minor' => 'integer', 'min_stock' => 'integer'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function onHand(): int
    {
        return (int) ($this->movements_sum_quantity ?? $this->movements()->sum('quantity'));
    }
}
