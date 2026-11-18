<?php

namespace App\Http\Resources;

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

        return $data;
    }
}
