<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TwoFactorChallenge extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'auth_version', 'mode', 'device_name', 'expires_at', 'used_at', 'attempts'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['auth_version' => 'integer', 'attempts' => 'integer', 'expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }
}
