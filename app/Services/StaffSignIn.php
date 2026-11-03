<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Support\Str;
use Laravel\WorkOS\User as WorkOSUser;

/**
 * Resolves the staff login for a WorkOS sign-in at one location.
 *
 * Staff sign-in through WorkOS is invite-only: it never creates a login or
 * grants access. An admin still adds staff to a store as before, and the
 * first WorkOS sign-in with that (WorkOS-verified) email claims the login.
 */
class StaffSignIn
{
    /**
     * The staff login for this WorkOS user, if it has access to the store.
     */
    public function find(WorkOSUser $workOSUser, Store $store): ?StoreUser
    {
        $storeUser = StoreUser::where('workos_id', $workOSUser->id)->first()
            ?? StoreUser::whereNull('workos_id')
                ->where('email', Str::lower($workOSUser->email))
                ->first();

        return $storeUser?->belongsToStore($store) ? $storeUser : null;
    }

    /**
     * Link the login to WorkOS on first sign-in. Someone who was invited but
     * never chose a password has now proved who they are, so the invitation
     * is retired too.
     */
    public function claim(StoreUser $storeUser, WorkOSUser $workOSUser): StoreUser
    {
        if ($storeUser->workos_id === null) {
            $storeUser->forceFill(['workos_id' => $workOSUser->id]);
        }

        if (! $storeUser->hasAcceptedInvitation()) {
            $storeUser->forceFill([
                'invitation_token' => null,
                'invitation_accepted_at' => now(),
                'email_verified_at' => now(),
            ]);
        }

        $storeUser->save();

        return $storeUser;
    }
}
