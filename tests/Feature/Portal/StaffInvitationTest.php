<?php

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Notifications\StoreStaffInvitationNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Jamie Admin']);
});

/**
 * The invitation link from the most recent staff invitation email.
 */
function sentInvitationUrl(StoreUser $storeUser): string
{
    $url = null;

    Notification::assertSentTo($storeUser, StoreStaffInvitationNotification::class, function ($notification) use ($storeUser, &$url) {
        $url = $notification->toMail($storeUser)->actionUrl;

        return true;
    });

    return $url;
}

describe('sending invitations', function () {
    beforeEach(function () {
        Notification::fake();
        $this->actingAs($this->admin);
    });

    test('adding a staff login to a live store emails them a link to choose a password', function () {
        $store = Store::factory()->create(['name' => 'Riverside Cremation', 'slug' => 'riverside']);

        Livewire::test('pages::admin.stores.show', ['store' => $store])
            ->set('staffName', 'Jordan Lee')
            ->set('staffEmail', 'jordan@example.com')
            ->call('createStaffUser')
            ->assertHasNoErrors();

        $storeUser = $store->staff()->where('email', 'jordan@example.com')->sole();
        expect($storeUser->hasAcceptedInvitation())->toBeFalse();

        $mail = null;
        Notification::assertSentTo($storeUser, StoreStaffInvitationNotification::class, function ($notification) use ($storeUser, &$mail) {
            $mail = $notification->toMail($storeUser);

            return true;
        });

        expect($mail->subject)->toBe("You're invited to the Riverside Cremation staff portal")
            ->and($mail->actionUrl)->toStartWith('https://riverside.'.config('app.root_domain').'/portal/invitation/'.$storeUser->id.'/')
            ->and(implode(' ', $mail->introLines))->toContain('Jamie Admin');
    });

    test('a draft store holds invitations back until it goes live', function () {
        $store = Store::factory()->draft()->create();

        $component = Livewire::test('pages::admin.stores.show', ['store' => $store])
            ->set('staffName', 'Jordan Lee')
            ->set('staffEmail', 'jordan@example.com')
            ->call('createStaffUser');

        $storeUser = $store->staff()->sole();
        Notification::assertNothingSent();

        $component->set('status', StoreStatus::Active->value)->call('save')->assertHasNoErrors();

        Notification::assertSentTo($storeUser, StoreStaffInvitationNotification::class);
    });

    test('resending an invitation cancels the earlier link', function () {
        $store = Store::factory()->create();
        $storeUser = StoreUser::factory()->for($store)->invited('first-token')->create();

        Livewire::test('pages::admin.stores.show', ['store' => $store])
            ->call('sendStaffInvitation', $storeUser->id);

        $newToken = Str::afterLast(sentInvitationUrl($storeUser), '/');

        expect($storeUser->fresh()->hasValidInvitation('first-token'))->toBeFalse()
            ->and($storeUser->fresh()->hasValidInvitation($newToken))->toBeTrue();
    });

    test('staff who already chose a password cannot be sent another invitation', function () {
        $store = Store::factory()->create();
        $storeUser = StoreUser::factory()->for($store)->create();

        Livewire::test('pages::admin.stores.show', ['store' => $store])
            ->call('sendStaffInvitation', $storeUser->id)
            ->assertNotFound();

        Notification::assertNothingSent();
    });

    test('the invitation email escapes the names it shows', function () {
        $store = Store::factory()->create(['name' => '<script>alert(1)</script> Home']);
        $storeUser = StoreUser::factory()->for($store)->invited()->create(['name' => '<b>Jordan</b>']);

        $html = (string) (new StoreStaffInvitationNotification($storeUser, $this->admin, 'token'))->toMail($storeUser)->render();

        expect($html)->not->toContain('<script>alert(1)</script>')
            ->not->toContain('<b>Jordan</b>')
            ->toContain('&lt;b&gt;Jordan&lt;/b&gt;');
    });
});

describe('accepting an invitation', function () {
    beforeEach(function () {
        $this->store = Store::factory()->create();
        actingAsTenant($this->store);

        $this->invitee = StoreUser::factory()->for($this->store)->invited('valid-token')->create([
            'email' => 'jordan@example.com',
        ]);
    });

    test('a valid invitation lets staff choose a password and signs them in', function () {
        $sessionIdBeforeAccepting = Session::getId();

        Livewire::test('pages::portal.accept-invitation', ['storeUser' => $this->invitee->id, 'token' => 'valid-token'])
            ->assertSee('Choose a password')
            ->set('password', 'a-strong-new-password')
            ->set('password_confirmation', 'a-strong-new-password')
            ->call('acceptInvitation')
            ->assertHasNoErrors()
            ->assertRedirect(route('portal.orders'));

        $invitee = $this->invitee->fresh();
        expect(Auth::guard('store')->id())->toBe($invitee->id)
            ->and(Hash::check('a-strong-new-password', $invitee->password))->toBeTrue()
            ->and($invitee->hasAcceptedInvitation())->toBeTrue()
            ->and($invitee->hasValidInvitation('valid-token'))->toBeFalse()
            ->and(Session::getId())->not->toBe($sessionIdBeforeAccepting);
    });

    test('the password must be confirmed', function () {
        Livewire::test('pages::portal.accept-invitation', ['storeUser' => $this->invitee->id, 'token' => 'valid-token'])
            ->set('password', 'a-strong-new-password')
            ->set('password_confirmation', 'something-else')
            ->call('acceptInvitation')
            ->assertHasErrors(['password' => 'confirmed']);

        expect($this->invitee->fresh()->hasAcceptedInvitation())->toBeFalse();
    });

    test('an unusable link cannot set a password', function (Closure $makeLinkUnusable, string $token) {
        $makeLinkUnusable($this->invitee);
        $passwordBefore = $this->invitee->fresh()->password;

        Livewire::test('pages::portal.accept-invitation', ['storeUser' => $this->invitee->id, 'token' => $token])
            ->assertSee('This link is no longer valid')
            ->set('password', 'a-strong-new-password')
            ->set('password_confirmation', 'a-strong-new-password')
            ->call('acceptInvitation');

        expect($this->invitee->fresh()->password)->toBe($passwordBefore)
            ->and(Auth::guard('store')->check())->toBeFalse();
    })->with([
        'wrong token' => [fn () => null, 'guessed-token'],
        'expired' => [fn (StoreUser $invitee) => $invitee->forceFill(['invited_at' => now()->subDays(StoreUser::INVITATION_LIFETIME_DAYS + 1)])->save(), 'valid-token'],
        'already used' => [fn (StoreUser $invitee) => $invitee->acceptInvitation('first-password'), 'valid-token'],
    ]);

    test('a link that was used after the page loaded cannot set the password again', function () {
        $component = Livewire::test('pages::portal.accept-invitation', ['storeUser' => $this->invitee->id, 'token' => 'valid-token']);

        $this->invitee->acceptInvitation('chosen-in-another-tab');

        $component->set('password', 'a-strong-new-password')
            ->set('password_confirmation', 'a-strong-new-password')
            ->call('acceptInvitation')
            ->assertSee('This link is no longer valid');

        expect(Hash::check('chosen-in-another-tab', $this->invitee->fresh()->password))->toBeTrue();
    });

    test('an invitation for another store\'s staff is not found', function () {
        $otherStoreInvitee = StoreUser::factory()->for(Store::factory())->invited('valid-token')->create();

        Livewire::test('pages::portal.accept-invitation', ['storeUser' => $otherStoreInvitee->id, 'token' => 'valid-token'])
            ->assertNotFound();
    });
});
