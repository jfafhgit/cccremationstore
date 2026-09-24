<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $workos_id
 * @property Carbon|null $approved_at
 * @property bool $is_super_admin
 * @property string|null $remember_token
 * @property string $avatar
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'workos_id', 'avatar'])]
#[Hidden(['workos_id', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_super_admin' => false,
    ];

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Whether a super admin has approved (or invited) this user into the admin area.
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Whether this user was invited but has not signed in through WorkOS yet.
     */
    public function hasPendingInvitation(): bool
    {
        return $this->workos_id === null;
    }

    /**
     * Whether this is the account configured as the platform's super admin.
     */
    public function isConfiguredSuperAdmin(): bool
    {
        $superAdminEmail = config('services.platform.super_admin_email');

        return filled($superAdminEmail) && Str::lower($this->email) === Str::lower($superAdminEmail);
    }

    /**
     * Grant admin access. Kept out of mass assignment so no form can set it.
     */
    public function approve(): void
    {
        $this->forceFill(['approved_at' => $this->approved_at ?? now()])->save();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function awaitingApproval(Builder $query): void
    {
        $query->whereNull('approved_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->whereNotNull('approved_at');
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
            'approved_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
