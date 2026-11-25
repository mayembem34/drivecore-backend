<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalAmendment extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'rental_id', 'created_by', 'type', 'changes', 'reason'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
