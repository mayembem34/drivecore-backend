<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'branch_id', 'registration', 'make', 'model', 'year', 'status', 'odometer'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'odometer' => 'integer'];
    }
}
