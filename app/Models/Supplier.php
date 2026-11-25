<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['company_id', 'name', 'email', 'phone', 'is_active'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'is_active' => 'boolean'];
    }
}
