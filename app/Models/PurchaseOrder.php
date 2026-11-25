<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'supplier_id', 'created_by', 'approved_by', 'po_number', 'status', 'total_minor', 'notes', 'submitted_at', 'approved_at', 'received_at'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'supplier_id' => 'integer', 'total_minor' => 'integer', 'submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }
}
