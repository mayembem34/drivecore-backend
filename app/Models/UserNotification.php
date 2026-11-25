<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class UserNotification extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'user_id', 'required_permission', 'severity', 'title', 'body', 'href', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_reads')->withPivot('read_at');
    }
}
