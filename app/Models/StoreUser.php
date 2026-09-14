<?php

namespace App\Models;

use App\Enums\StoreUserRole;
use Database\Factories\StoreUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A funeral home staff member. Scoped to exactly one store.
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property string $email
 * @property StoreUserRole $role
 */
class StoreUser extends Authenticatable
{
    /** @use HasFactory<StoreUserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'store_id',
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => StoreUserRole::class,
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isOwner(): bool
    {
        return $this->role === StoreUserRole::Owner;
    }
}
