<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    protected $fillable = ['assigned_to_user_id', 'work_order_type', 'complaint', 'diagnosis', 'technician_name', 'estimate_minor', 'schedule_id', 'release_passed', 'release_notes', 'company_id', 'branch_id', 'vehicle_id', 'created_by', 'title', 'status', 'due_at', 'started_at', 'completed_at', 'labour_cost_minor', 'parts_cost_minor', 'notes', 'completion_notes', 'expense_id'];

    protected function casts(): array
    {
        return ['release_passed' => 'boolean', 'company_id' => 'integer', 'branch_id' => 'integer', 'vehicle_id' => 'integer', 'due_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'labour_cost_minor' => 'integer', 'parts_cost_minor' => 'integer', 'expense_id' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
