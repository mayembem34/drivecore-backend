<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'invoice_id', 'customer_id', 'credit_note_number', 'subtotal_minor', 'vat_minor', 'total_minor', 'reason', 'status', 'approval_request_id', 'journal_entry_id', 'posted_at'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'invoice_id' => 'integer', 'customer_id' => 'integer', 'subtotal_minor' => 'integer', 'vat_minor' => 'integer', 'total_minor' => 'integer', 'approval_request_id' => 'integer', 'journal_entry_id' => 'integer', 'posted_at' => 'immutable_datetime'];
    }
}
