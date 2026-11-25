<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Quotation extends Model
{
    protected $fillable = ['customer_accepted_at', 'customer_acceptance_ref', 'rate_card_id', 'company_id', 'branch_id', 'customer_id', 'created_by', 'starts_at', 'ends_at', 'rental_days', 'status', 'discount_pct', 'discount_status', 'discount_decided_by', 'discount_decided_at', 'discount_decision_notes', 'subtotal_minor', 'discount_minor', 'vat_rate_pct', 'vat_minor', 'total_minor', 'notes', 'converted_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'converted_at' => 'immutable_datetime', 'discount_decided_at' => 'immutable_datetime', 'discount_pct' => 'float', 'vat_rate_pct' => 'float'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_decided_by');
    }

    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'quotation_vehicles')->withPivot(['daily_rate_minor', 'line_subtotal_minor', 'reservation_id'])->withTimestamps();
    }
}
