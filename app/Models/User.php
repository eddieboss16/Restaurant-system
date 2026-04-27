<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'pin',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function customerSessions(): HasMany
    {
        return $this->hasMany(CustomerSession::class, 'waiter_id');
    }

    public function isWaiter(): bool
    {
        return $this->role === 'waiter';
    }

    public function isKitchen(): bool
    {
        return $this->role === 'kitchen';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isPrimaryAdmin(): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        return static::where('role', 'admin')->min('id') === $this->id;
    }
}
