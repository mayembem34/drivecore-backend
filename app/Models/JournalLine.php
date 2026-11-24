<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $fillable = ['journal_entry_id', 'gl_account_id', 'debit_minor', 'credit_minor'];

    protected function casts(): array
    {
        return ['journal_entry_id' => 'integer', 'gl_account_id' => 'integer', 'debit_minor' => 'integer', 'credit_minor' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
