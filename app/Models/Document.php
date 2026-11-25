<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = ['category', 'company_id', 'branch_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size', 'entity_type', 'entity_id', 'expires_at'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'uploaded_by' => 'integer', 'size' => 'integer', 'entity_id' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
