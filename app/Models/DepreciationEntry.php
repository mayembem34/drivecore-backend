<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepreciationEntry extends Model
{
    protected $fillable = ['company_id', 'fixed_asset_id', 'period_month', 'amount_minor', 'journal_entry_id', 'posted_at'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'fixed_asset_id' => 'integer', 'amount_minor' => 'integer', 'journal_entry_id' => 'integer', 'posted_at' => 'immutable_datetime'];
    }
}
