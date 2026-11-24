<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'customer_id', 'custom_customer_name', 'custom_customer_email', 'custom_customer_phone', 'rental_id', 'created_by', 'invoice_number', 'status', 'due_at', 'issued_at', 'voided_at', 'discount_pct', 'subtotal_minor', 'discount_minor', 'vat_rate_pct', 'vat_minor', 'total_minor', 'notes'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'customer_id' => 'integer', 'rental_id' => 'integer', 'due_at' => 'immutable_datetime', 'issued_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime', 'discount_pct' => 'float', 'subtotal_minor' => 'integer', 'discount_minor' => 'integer', 'vat_rate_pct' => 'float', 'vat_minor' => 'integer', 'total_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function balances(): array
    {
        $paid = (int) $this->payments->sum('amount_minor');
        $refunded = (int) $this->refunds->sum('amount_minor');
        $outstanding = $this->status === 'issued' ? max(0, $this->total_minor - $paid) : 0;

        return ['paid_minor' => $paid, 'refunded_minor' => $refunded, 'net_paid_minor' => $paid - $refunded, 'outstanding_minor' => $outstanding, 'is_overdue' => $outstanding > 0 && $this->due_at->isPast()];
    }
}
