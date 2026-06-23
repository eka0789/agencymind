<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Laravel\Sanctum\HasApiTokens;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, ['owner', 'admin']);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'discord_user_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => 'string',
        ];
    }

    /**
     * Check if user is owner.
     */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Check if user is lead dev.
     */
    public function isLeadDev(): bool
    {
        return $this->role === 'lead_dev';
    }

    /**
     * Check if user is developer.
     */
    public function isDeveloper(): bool
    {
        return $this->role === 'developer';
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * The clients that the user has access to.
     */
    public function clients()
    {
        return $this->belongsToMany(Client::class, 'user_client_access')
            ->withPivot('access_level')
            ->withTimestamps();
    }

    /**
     * Check if user has access to a specific client.
     */
    public function hasAccessToClient(Client $client): bool
    {
        // Owner and Admin have global access
        if ($this->isOwner() || $this->isAdmin()) {
            return true;
        }

        return $this->clients()->where('client_id', $client->id)->exists();
    }
}
