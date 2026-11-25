<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'name', 'bank_name', 'account_number_masked', 'currency', 'gl_account_code', 'opening_balance_minor', 'last_reconciled_at', 'is_active'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'opening_balance_minor' => 'integer', 'last_reconciled_at' => 'immutable_datetime', 'is_active' => 'boolean'];
    }
}
