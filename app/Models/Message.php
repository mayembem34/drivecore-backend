<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Message extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'sender_user_id', 'channel', 'body', 'related_entity_type', 'related_entity_id'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_reads')->withPivot('read_at');
    }
}
