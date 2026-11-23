<?php

namespace App\Http\Resources;

use App\Models\Rental;
use App\Models\Reservation;
use App\Models\Vehicle;
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
            $data += ['odometer_unit' => 'km', 'customer_id' => $this->reservation?->customer_id, 'customer_name' => $this->reservation?->customer?->name, 'vehicle_registration' => $this->vehicle?->registration, 'vehicle_label' => trim(($this->vehicle?->make ?? '').' '.($this->vehicle?->model ?? '')), 'driver_name' => $this->driver?->name, 'starts_at' => $this->reservation?->starts_at?->toISOString(), 'ends_at' => $this->reservation?->ends_at?->toISOString()];
        }

        return $data;
    }
}
