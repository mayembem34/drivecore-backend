<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['credit_limit_minor' => 'integer', 'payment_terms_days' => 'integer', 'is_on_credit_hold' => 'boolean'];
    }

    protected $fillable = ['driver_licence_expires_at', 'contact_person_name', 'contact_person_phone', 'billing_address', 'id_number', 'driver_licence_number', 'company_registration', 'tin', 'credit_limit_minor', 'payment_terms_days', 'is_on_credit_hold', 'credit_hold_reason', 'company_id', 'branch_id', 'name', 'type', 'email', 'phone'];
}
