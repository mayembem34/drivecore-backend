<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoice extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'supplier_id', 'purchase_order_id', 'invoice_number', 'invoice_date', 'due_at', 'subtotal_minor', 'vat_minor', 'total_minor', 'paid_minor', 'expense_account_code', 'match_status', 'variance_minor', 'approval_request_id', 'status', 'notes'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'supplier_id' => 'integer', 'purchase_order_id' => 'integer', 'invoice_date' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'subtotal_minor' => 'integer', 'vat_minor' => 'integer', 'total_minor' => 'integer', 'paid_minor' => 'integer', 'variance_minor' => 'integer', 'approval_request_id' => 'integer'];
    }
}
