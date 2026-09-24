<?php

use App\Models\User;
use App\Notifications\AdminAccessRequestedNotification;
use App\Notifications\AdminInvitationNotification;
use App\Services\AdminSignIn;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Laravel\WorkOS\User as WorkOSUser;
use Livewire\Livewire;

function workOSUser(string $email, string $id = 'user_new', string $firstName = 'Pat', string $lastName = 'Doe'): WorkOSUser
{
    return new WorkOSUser(id: $id, organizationId: null, firstName: $firstName, lastName: $lastName, email: $email);
}

/**
 * Mirrors what AuthKitAuthenticationRequest::authenticate() does with the
 * callbacks we hand it in routes/auth.php, without calling WorkOS.
 */
function signInThroughWorkOS(WorkOSUser $workOSUser): User
{
    $adminSignIn = app(AdminSignIn::class);

    $user = $adminSignIn->find($workOSUser);

    return $user ? $adminSignIn->update($user, $workOSUser) : $adminSignIn->create($workOSUser);
}

describe('route access', function () {
    test('a user awaiting approval cannot reach the admin area or settings', function () {
        $this->actingAs(User::factory()->awaitingApproval()->create());

        $this->get('/admin/stores')->assertForbidden();
        $this->get('/settings/profile')->assertForbidden();
    });

    test('a user awaiting approval is sent from the dashboard to the pending approval page', function () {
        $this->actingAs(User::factory()->awaitingApproval()->create());

        $this->get('/dashboard')->assertRedirect(route('pending-approval'));
        $this->get(route('pending-approval'))->assertOk()->assertSee('Awaiting approval');
    });

    test('an approved admin is sent from the pending approval page to the dashboard', function () {
        $this->actingAs(User::factory()->create());

        $this->get(route('pending-approval'))->assertRedirect(route('dashboard'));
    });

    test('only a super admin can open the admin users page', function () {
        $this->actingAs(User::factory()->create());
        $this->get(route('admin.users'))->assertForbidden();

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->get(route('admin.users'))->assertOk();
    });
});

test('admin gates', function (string $state, bool $canAccessAdmin, bool $canManageAdmins) {
    $user = match ($state) {
        'awaiting approval' => User::factory()->awaitingApproval()->create(),
        'approved' => User::factory()->create(),
        'super admin' => User::factory()->superAdmin()->create(),
        'unapproved super admin' => User::factory()->superAdmin()->awaitingApproval()->create(),
    };

    expect(Gate::forUser($user)->allows('access-admin'))->toBe($canAccessAdmin)
        ->and(Gate::forUser($user)->allows('manage-admins'))->toBe($canManageAdmins);
})->with([
    ['awaiting approval', false, false],
    ['approved', true, false],
    ['super admin', true, true],
    ['unapproved super admin', false, false],
]);

describe('managing admins', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($this->superAdmin);
    });

    test('a super admin can invite someone, who is pre-approved and emailed', function () {
        Notification::fake();

        Livewire::test('pages::admin.users')
            ->set('email', '  New.Admin@Altmeyer.com ')
            ->call('invite')
            ->assertHasNoErrors();

        $invited = User::where('email', 'new.admin@altmeyer.com')->sole();
        expect($invited->isApproved())->toBeTrue()
            ->and($invited->hasPendingInvitation())->toBeTrue()
            ->and($invited->is_super_admin)->toBeFalse();

        Notification::assertSentTo($invited, AdminInvitationNotification::class);
    });

    test('an invitation needs a valid email that is not already in use', function (string $email, string $message) {
        User::factory()->awaitingApproval()->create(['email' => 'taken@altmeyer.com']);

        Livewire::test('pages::admin.users')
            ->set('email', $email)
            ->call('invite')
            ->assertHasErrors('email')
            ->assertSee($message);
    })->with([
        ['not-an-email', 'The email field must be a valid email address.'],
        ['Taken@altmeyer.com', 'That email already has an account or a pending request.'],
    ]);

    test('a super admin can resend an admin invitation', function () {
        Notification::fake();
        $invited = User::factory()->invited()->create();

        Livewire::test('pages::admin.users')->call('resendInvitation', $invited->id);

        Notification::assertSentTo($invited, AdminInvitationNotification::class);
    });

    test('a super admin can approve an access request', function () {
        $requester = User::factory()->awaitingApproval()->create();

        Livewire::test('pages::admin.users')->call('approve', $requester->id);

        expect($requester->fresh()->isApproved())->toBeTrue();
    });

    test('a super admin can remove an admin', function () {
        $admin = User::factory()->create();

        Livewire::test('pages::admin.users')->call('remove', $admin->id);

        expect($admin->fresh())->toBeNull();
    });

    test('a super admin cannot be removed', function () {
        Livewire::test('pages::admin.users')
            ->call('remove', $this->superAdmin->id)
            ->assertNotFound();

        expect($this->superAdmin->fresh())->not->toBeNull();
    });

    test('an approved admin who is not a super admin cannot manage admins', function () {
        $requester = User::factory()->awaitingApproval()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::admin.users')
            ->call('approve', $requester->id)
            ->assertForbidden();

        expect($requester->fresh()->isApproved())->toBeFalse();
    });
});

describe('signing in through WorkOS', function () {
    test('a first-time sign-in becomes an access request and notifies super admins', function () {
        Notification::fake();
        $superAdmin = User::factory()->superAdmin()->create();

        $user = signInThroughWorkOS(workOSUser('stranger@altmeyer.com'));

        expect($user->isApproved())->toBeFalse()
            ->and($user->is_super_admin)->toBeFalse()
            ->and($user->name)->toBe('Pat Doe');

        Notification::assertSentTo($superAdmin, AdminAccessRequestedNotification::class);
    });

    test('an invited email claims its invitation on first sign-in', function () {
        Notification::fake();
        $invited = User::factory()->invited()->create(['email' => 'invited@altmeyer.com', 'name' => 'invited@altmeyer.com']);

        $user = signInThroughWorkOS(workOSUser('Invited@altmeyer.com', 'user_invited'));

        expect($user->id)->toBe($invited->id)
            ->and($user->workos_id)->toBe('user_invited')
            ->and($user->name)->toBe('Pat Doe')
            ->and($user->isApproved())->toBeTrue();

        Notification::assertNothingSent();
    });

    test('an invitation already claimed by one WorkOS account cannot be taken over by another', function () {
        User::factory()->create(['email' => 'owner@altmeyer.com', 'workos_id' => 'user_owner']);

        expect(app(AdminSignIn::class)->find(workOSUser('owner@altmeyer.com', 'user_intruder')))->toBeNull();
    });

    test('the configured super admin email is approved and promoted on sign-in', function () {
        config(['services.platform.super_admin_email' => 'Owner@altmeyer.com']);

        $user = signInThroughWorkOS(workOSUser('owner@altmeyer.com'));

        expect($user->isApproved())->toBeTrue()
            ->and($user->is_super_admin)->toBeTrue();
    });

    test('a returning user is matched by WorkOS id and keeps their approval state', function () {
        $existing = User::factory()->awaitingApproval()->create(['workos_id' => 'user_returning']);

        $user = signInThroughWorkOS(workOSUser('changed@altmeyer.com', 'user_returning'));

        expect($user->id)->toBe($existing->id)
            ->and($user->isApproved())->toBeFalse();
    });
});
