<?php

use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Support\Facades\Session;

beforeEach(function () {
    $this->north = Store::factory()->create(['name' => 'North Chapel', 'slug' => 'north']);
    $this->south = Store::factory()->create(['name' => 'South Chapel', 'slug' => 'south']);

    $this->storeUser = StoreUser::factory()->forStore($this->north)->create(['email' => 'pat@example.com']);
});

/**
 * Start a staff WorkOS sign-in at a location and come back from WorkOS,
 * returning the response to the callback.
 */
function returnFromStaffSignIn(string $store): mixed
{
    test()->get(route('staff-sso.redirect', ['store' => $store]))
        ->assertRedirectContains('https://api.workos.com/user_management/authorize');

    return test()->get(route('staff-sso.callback', [
        'code' => 'authorization-code',
        'state' => json_encode(['state' => Session::get('staff_sso.state')]),
    ]));
}

describe('store staff', function () {
    test('signing in through WorkOS lands them in their own store\'s portal', function () {
        fakeWorkOS(['id' => 'user_workos_1', 'email' => 'pat@example.com']);

        $handoffUrl = returnFromStaffSignIn('north')
            ->assertRedirectContains('https://north.')
            ->headers->get('Location');

        $this->get($handoffUrl)->assertRedirect(route('portal.orders', ['store' => 'north']));

        $this->assertAuthenticatedAs($this->storeUser, 'store');
        expect($this->storeUser->fresh()->workos_id)->toBe('user_workos_1');
    });

    test('they are turned away from a store they do not have access to', function () {
        fakeWorkOS(['email' => 'pat@example.com']);

        returnFromStaffSignIn('south')
            ->assertRedirect(route('portal.login', ['store' => 'south', 'sso' => 'no-access']));

        $this->assertGuest('store');
        $this->get(route('portal.login', ['store' => 'south', 'sso' => 'no-access']))
            ->assertSee('does not have access to this location');
    });

    test('an email with no staff login is rejected', function (array $workOSUser) {
        fakeWorkOS($workOSUser);

        returnFromStaffSignIn('north')
            ->assertRedirect(route('portal.login', ['store' => 'north', 'sso' => 'no-access']));

        $this->assertGuest('store');
        expect($this->storeUser->fresh()->workos_id)->toBeNull();
    })->with([
        'unknown email' => [['email' => 'stranger@example.com']],
        'unverified email' => [['email' => 'pat@example.com', 'email_verified' => false]],
    ]);

    test('a login already linked to one WorkOS account cannot be claimed by another', function () {
        $this->storeUser->forceFill(['workos_id' => 'user_workos_original'])->save();
        fakeWorkOS(['id' => 'user_workos_other', 'email' => 'pat@example.com']);

        returnFromStaffSignIn('north')
            ->assertRedirect(route('portal.login', ['store' => 'north', 'sso' => 'no-access']));
    });

    test('an invited staff member who signs in through WorkOS accepts their invitation', function () {
        $invited = StoreUser::factory()->invited()->forStore($this->north)->create(['email' => 'new@example.com']);
        fakeWorkOS(['id' => 'user_workos_new', 'email' => 'new@example.com']);

        returnFromStaffSignIn('north')->assertRedirectContains('https://north.');

        $invited->refresh();
        expect($invited->hasAcceptedInvitation())->toBeTrue()
            ->and($invited->invitation_token)->toBeNull();
    });

    test('a callback without the state from this browser\'s sign-in is refused', function () {
        fakeWorkOS();

        $this->get(route('staff-sso.callback', ['code' => 'authorization-code', 'state' => json_encode(['state' => 'forged'])]))
            ->assertForbidden();
    });

    test('they can sign out of the portal', function () {
        $this->actingAs($this->storeUser, 'store')
            ->post(route('portal.logout', ['store' => 'north']))
            ->assertRedirect(route('portal.login', ['store' => 'north']));

        $this->assertGuest('store');
    });
});

describe('admins', function () {
    test('an invited admin signs in through WorkOS and goes to the dashboard', function () {
        $admin = User::factory()->invited()->create(['email' => 'pat@example.com']);
        fakeWorkOS(['id' => 'user_workos_admin', 'email' => 'pat@example.com']);

        $this->get(route('login'))->assertRedirectContains('https://api.workos.com/user_management/authorize');

        $this->get('/authenticate?'.http_build_query(['code' => 'authorization-code', 'state' => Session::get('state')]))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin, 'web');
        expect($admin->fresh()->workos_id)->toBe('user_workos_admin');
    });

    test('a staff WorkOS sign-in does not sign anyone in as an admin', function () {
        User::factory()->create(['email' => 'pat@example.com', 'workos_id' => 'user_workos_1']);
        fakeWorkOS(['id' => 'user_workos_1', 'email' => 'pat@example.com']);

        returnFromStaffSignIn('north');

        $this->assertGuest('web');
    });

    test('they can sign out', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest('web');
    });
});
