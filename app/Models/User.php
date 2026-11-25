<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'auth_version'])]
class User extends Authenticatable
{
    protected $attributes = ['is_active' => true, 'is_platform_admin' => false];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, \Spatie\Permission\Traits\HasRoles;

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('branch_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
            'two_factor_secret' => 'encrypted', 'two_factor_recovery_codes' => 'encrypted:array', 'two_factor_confirmed_at' => 'immutable_datetime', 'password' => 'hashed', 'must_change_password' => 'boolean', 'auth_version' => 'integer',
        ];
    }
}
