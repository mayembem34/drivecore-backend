<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankTransaction extends Model
{
    protected $fillable = ['company_id', 'bank_account_id', 'transaction_date', 'description', 'reference', 'amount_minor', 'match_status', 'matched_entity_type', 'matched_entity_id', 'reconciliation_id'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'bank_account_id' => 'integer', 'transaction_date' => 'immutable_datetime', 'amount_minor' => 'integer', 'matched_entity_id' => 'integer', 'reconciliation_id' => 'integer'];
    }
}
