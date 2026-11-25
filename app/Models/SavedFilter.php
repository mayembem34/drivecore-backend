<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedFilter extends Model
{
    protected $fillable = ['company_id', 'user_id', 'module', 'name', 'filters', 'is_shared', 'requires_finance'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'is_shared' => 'boolean', 'requires_finance' => 'boolean'];
    }
}
