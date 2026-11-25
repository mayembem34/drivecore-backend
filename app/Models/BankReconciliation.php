<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliation extends Model
{
    protected $fillable = ['company_id', 'bank_account_id', 'statement_date', 'statement_closing_balance_minor', 'reconciled_balance_minor', 'status', 'closed_at'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'bank_account_id' => 'integer', 'statement_date' => 'immutable_datetime', 'statement_closing_balance_minor' => 'integer', 'reconciled_balance_minor' => 'integer', 'closed_at' => 'immutable_datetime'];
    }
}
