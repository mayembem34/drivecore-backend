<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    protected $fillable = ['company_id', 'supplier_invoice_id', 'bank_account_id', 'amount_minor', 'method', 'reference', 'paid_at', 'journal_entry_id'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'supplier_invoice_id' => 'integer', 'bank_account_id' => 'integer', 'amount_minor' => 'integer', 'paid_at' => 'immutable_datetime', 'journal_entry_id' => 'integer'];
    }
}
