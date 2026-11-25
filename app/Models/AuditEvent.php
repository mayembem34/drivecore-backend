<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $fillable = ['branch_id', 'company_id', 'user_id', 'action', 'subject_type', 'subject_id', 'metadata'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
