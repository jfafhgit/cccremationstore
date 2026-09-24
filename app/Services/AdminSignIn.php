<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AdminAccessRequestedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\WorkOS\User as WorkOSUser;

/**
 * Resolves the local User for a WorkOS (Google) sign-in.
 *
 * Signing in through WorkOS only proves who someone is — it does not grant
 * admin access. A brand new identity becomes an access request that a super
 * admin must approve, unless a super admin already invited that email, in
 * which case the invitation is claimed and access is immediate.
 */
class AdminSignIn
{
    /**
     * Find the user by WorkOS id, falling back to an unclaimed invitation for
     * the same email address.
     */
    public function find(WorkOSUser $workOSUser): ?User
    {
        return User::where('workos_id', $workOSUser->id)->first()
            ?? User::whereNull('workos_id')
                ->where('email', Str::lower($workOSUser->email))
                ->first();
    }

    /**
     * Record a first-time sign-in as an access request, and let the super
     * admins know someone is waiting.
     */
    public function create(WorkOSUser $workOSUser): User
    {
        $user = User::create([
            'name' => $this->nameFrom($workOSUser),
            'email' => Str::lower($workOSUser->email),
            'workos_id' => $workOSUser->id,
            'avatar' => $workOSUser->avatar ?? '',
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->promoteConfiguredSuperAdmin($user);

        if (! $user->isApproved()) {
            Notification::send(
                User::where('is_super_admin', true)->get(),
                new AdminAccessRequestedNotification($user),
            );
        }

        return $user;
    }

    /**
     * Refresh profile details, and claim the invitation on first sign-in.
     */
    public function update(User $user, WorkOSUser $workOSUser): User
    {
        if ($user->hasPendingInvitation()) {
            $user->forceFill([
                'workos_id' => $workOSUser->id,
                'name' => $this->nameFrom($workOSUser),
                'email_verified_at' => now(),
            ]);
        }

        $user->forceFill(['avatar' => $workOSUser->avatar ?? ''])->save();

        $this->promoteConfiguredSuperAdmin($user);

        return $user;
    }

    protected function promoteConfiguredSuperAdmin(User $user): void
    {
        if ($user->isConfiguredSuperAdmin()) {
            $user->forceFill([
                'approved_at' => $user->approved_at ?? now(),
                'is_super_admin' => true,
            ])->save();
        }
    }

    protected function nameFrom(WorkOSUser $workOSUser): string
    {
        return trim($workOSUser->firstName.' '.$workOSUser->lastName) ?: $workOSUser->email;
    }
}
