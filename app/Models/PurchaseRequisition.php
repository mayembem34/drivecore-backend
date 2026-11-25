<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequisition extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'requester_id', 'approver_id', 'supplier_id', 'requisition_number', 'department', 'needed_by', 'justification', 'items', 'estimated_total_minor', 'status', 'purchase_order_id'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'requester_id' => 'integer', 'approver_id' => 'integer', 'supplier_id' => 'integer', 'needed_by' => 'immutable_datetime', 'items' => 'array', 'estimated_total_minor' => 'integer', 'purchase_order_id' => 'integer'];
    }
}
