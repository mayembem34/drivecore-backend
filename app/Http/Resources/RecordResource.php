<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(array_diff(array_merge(['id', 'created_at', 'updated_at'], $this->resource->getFillable()), ['disk', 'path']));
    }
}
