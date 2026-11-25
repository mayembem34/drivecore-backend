<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Rental;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource->only(array_diff(array_merge(['id', 'created_at', 'updated_at'], $this->resource->getFillable()), ['disk', 'path']));
        if ($this->resource instanceof Vehicle) {
            $data['odometer_unit'] = 'km';
        }

        if ($this->resource instanceof Reservation && $this->resource->relationLoaded('customer')) {
            $data += ['customer_name' => $this->customer?->name, 'vehicle_registration' => $this->vehicle?->registration, 'vehicle_label' => trim(($this->vehicle?->make ?? '').' '.($this->vehicle?->model ?? '')), 'has_rental' => $this->rental !== null, 'rental_id' => $this->rental?->id];
        }
        if ($this->resource instanceof Rental && $this->resource->relationLoaded('reservation')) {
            $data += ['odometer_unit' => 'km', 'customer_id' => $this->reservation?->customer_id, 'customer_name' => $this->reservation?->customer?->name, 'vehicle_registration' => $this->vehicle?->registration, 'vehicle_label' => trim(($this->vehicle?->make ?? '').' '.($this->vehicle?->model ?? '')), 'invoice_id' => $this->whenLoaded('invoice', fn () => $this->invoice?->id), 'driver_name' => $this->driver?->name, 'starts_at' => $this->reservation?->starts_at?->toISOString(), 'ends_at' => $this->reservation?->ends_at?->toISOString()];
        }

        if ($this->resource instanceof Quotation) {
            $data += ['customer_name' => $this->customer?->name, 'discount_decided_by_name' => $this->decider?->name, 'vehicles' => $this->vehicles->map(fn ($v) => ['vehicle_id' => $v->id, 'registration' => $v->registration, 'make' => $v->make, 'model' => $v->model, 'daily_rate_minor' => (int) $v->pivot->daily_rate_minor, 'line_subtotal_minor' => (int) $v->pivot->line_subtotal_minor, 'reservation_id' => $v->pivot->reservation_id === null ? null : (int) $v->pivot->reservation_id])->all(), 'reservation_ids' => $this->vehicles->pluck('pivot.reservation_id')->filter()->map(fn ($id) => (int) $id)->values()->all()];
        }

        if ($this->resource instanceof Invoice) {
            $data += $this->resource->balances() + ['customer_name' => $this->customer?->name ?? $this->custom_customer_name, 'lines' => $this->lines->toArray(), 'payments' => $this->payments->toArray(), 'refunds' => $this->refunds->toArray()];
        }
        if ($this->resource instanceof JournalEntry) {
            $data['lines'] = $this->lines->map(fn ($line) => ['account_code' => $line->account->code, 'account_name' => $line->account->name, 'debit_minor' => $line->debit_minor, 'credit_minor' => $line->credit_minor])->all();
        }

        if ($this->resource instanceof Part) {
            $onHand = $this->resource->onHand();
            $data += ['on_hand' => $onHand, 'is_low_stock' => $onHand <= $this->min_stock];
        }
        if ($this->resource instanceof WorkOrder) {
            $data += ['vehicle_registration' => $this->vehicle?->registration, 'vehicle_label' => trim(($this->vehicle?->make ?? '').' '.($this->vehicle?->model ?? '')), 'total_cost_minor' => $this->labour_cost_minor + $this->parts_cost_minor, 'movements' => $this->movements->toArray()];
        }
        if ($this->resource instanceof PurchaseOrder) {
            $data += ['supplier_name' => $this->supplier?->name, 'lines' => $this->lines->toArray()];
        }
        if ($this->resource instanceof StockMovement && $this->resource->relationLoaded('part')) {
            $data['part_name'] = $this->part?->name;
        }

        return $data;
    }
}
