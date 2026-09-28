<?php

namespace App\Models;

use App\Enums\StoreUserRole;
use App\Notifications\StoreStaffInvitationNotification;
use Carbon\CarbonInterface;
use Database\Factories\StoreUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A funeral home staff member. One login (email and password) can have
 * access to several locations, with its own role at each.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $invitation_token
 * @property Carbon|null $invited_at
 * @property Carbon|null $invitation_accepted_at
 */
class StoreUser extends Authenticatable
{
    /** @use HasFactory<StoreUserFactory> */
    use HasFactory, Notifiable;

    public const INVITATION_LIFETIME_DAYS = 7;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'invitation_token',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'invited_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
        ];
    }

    /**
     * The locations this login can sign in to.
     *
     * @return BelongsToMany<Store, $this, StoreMembership, 'membership'>
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_memberships')
            ->using(StoreMembership::class)
            ->as('membership')
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * This login's role at the store, or null when it has no access there.
     */
    public function roleAt(Store $store): ?StoreUserRole
    {
        return StoreMembership::query()
            ->where('store_user_id', $this->id)
            ->where('store_id', $store->id)
            ->first()
            ?->role;
    }

    public function belongsToStore(Store $store): bool
    {
        return $this->roleAt($store) !== null;
    }

    public function isOwnerOf(Store $store): bool
    {
        return $this->roleAt($store) === StoreUserRole::Owner;
    }

    /**
     * Whether this staff member has chosen a password from their invitation.
     */
    public function hasAcceptedInvitation(): bool
    {
        return $this->invitation_accepted_at !== null;
    }

    /**
     * Email a fresh invitation link. Only a hash of the token is stored, and
     * replacing it invalidates any link sent before this one.
     */
    public function sendInvitation(Store $store, User $invitedBy): void
    {
        $token = Str::random(64);

        $this->forceFill([
            'invitation_token' => hash('sha256', $token),
            'invited_at' => now(),
        ])->save();

        $this->notify(new StoreStaffInvitationNotification($this, $store, $invitedBy, $token));
    }

    /**
     * The "choose your password" page on the inviting store's subdomain.
     */
    public function invitationUrl(Store $store, string $token): string
    {
        return route('portal.invitation', [
            'store' => $store->slug,
            'storeUser' => $this->id,
            'token' => $token,
        ]);
    }

    public function invitationExpiresAt(): CarbonInterface
    {
        return $this->invited_at->addDays(self::INVITATION_LIFETIME_DAYS);
    }

    /**
     * Whether the token is from the most recent invitation, which has not
     * been used or expired yet.
     */
    public function hasValidInvitation(string $token): bool
    {
        return ! $this->hasAcceptedInvitation()
            && $this->invitation_token !== null
            && hash_equals($this->invitation_token, hash('sha256', $token))
            && $this->invitationExpiresAt()->isFuture();
    }

    /**
     * Set the password the staff member chose and retire the invitation.
     */
    public function acceptInvitation(string $password): void
    {
        $this->forceFill([
            'password' => $password,
            'invitation_token' => null,
            'invitation_accepted_at' => now(),
            'email_verified_at' => now(),
        ])->save();
    }
}
