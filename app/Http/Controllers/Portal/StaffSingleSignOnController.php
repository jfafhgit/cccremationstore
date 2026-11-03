<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\StaffSignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\WorkOS\User as WorkOSUser;
use Laravel\WorkOS\WorkOS;
use WorkOS\Exception\WorkOSException;
use WorkOS\UserManagement;

/**
 * Staff sign-in through WorkOS. Every store is its own subdomain with its own
 * session, so the round trip to WorkOS starts and ends on the root domain —
 * one redirect URI per environment, shared with any other Treasured Memories
 * site on the same WorkOS environment — and the staff member is then handed
 * to their store with the same single-use link the location switcher uses.
 */
class StaffSingleSignOnController extends Controller
{
    private const SESSION_KEY = 'staff_sso';

    /**
     * Send the staff member to WorkOS, remembering which location they were
     * signing in to.
     */
    public function redirect(Request $request, UserManagement $userManagement, string $store): RedirectResponse
    {
        $location = Store::active()->where('slug', $store)->firstOrFail();

        WorkOS::configure();

        $state = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'store_id' => $location->id,
        ]);

        return redirect()->away($userManagement->getAuthorizationUrl(
            config('services.workos.staff_redirect_url') ?: route('staff-sso.callback'),
            ['state' => $state],
            UserManagement::AUTHORIZATION_PROVIDER_AUTHKIT,
        ));
    }

    /**
     * Back from WorkOS: check the person has access to the location they
     * started from, then hand them over to it. Anyone else goes back to that
     * location's sign-in page with an explanation.
     */
    public function callback(Request $request, UserManagement $userManagement, StaffSignIn $staffSignIn): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $returnedState = json_decode((string) $request->query('state'), true)['state'] ?? null;

        if (! is_array($pending) || ! is_string($returnedState) || ! hash_equals($pending['state'], $returnedState)) {
            abort(403);
        }

        $location = Store::active()->findOrFail($pending['store_id']);

        WorkOS::configure();

        try {
            $authenticated = $userManagement->authenticateWithCode(
                config('services.workos.client_id'),
                (string) $request->query('code'),
            );
        } catch (WorkOSException $exception) {
            report($exception);

            return $this->backToSignIn($location, 'failed');
        }

        $workOSUser = new WorkOSUser(
            id: $authenticated->user->id,
            organizationId: $authenticated->organizationId,
            firstName: $authenticated->user->firstName,
            lastName: $authenticated->user->lastName,
            email: $authenticated->user->email,
            avatar: $authenticated->user->profilePictureUrl,
        );

        $storeUser = $authenticated->user->emailVerified
            ? $staffSignIn->find($workOSUser, $location)
            : null;

        if (! $storeUser) {
            return $this->backToSignIn($location, 'no-access');
        }

        $staffSignIn->claim($storeUser, $workOSUser);

        return redirect()->away(LocationSwitchController::handoffUrl($storeUser, $location));
    }

    /**
     * The location's own sign-in page. The reason travels in the query string
     * because the root domain's session is not shared with the subdomain.
     */
    private function backToSignIn(Store $location, string $reason): RedirectResponse
    {
        return redirect()->away(route('portal.login', ['store' => $location->slug, 'sso' => $reason]));
    }
}
