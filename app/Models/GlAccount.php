<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlAccount extends Model
{
    protected $fillable = ['company_id', 'code', 'name', 'type', 'is_system'];

    protected function casts(): array
    {
        return ['company_id' => 'integer'];
    }
}
