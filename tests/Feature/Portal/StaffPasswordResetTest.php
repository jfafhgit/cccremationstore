<?php

use App\Models\Store;
use App\Models\StoreUser;
use App\Notifications\StaffPasswordResetNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Riverside Cremation', 'slug' => 'riverside']);
    actingAsTenant($this->store);

    $this->staff = StoreUser::factory()->forStore($this->store)->create(['email' => 'jordan@example.com']);
});

/**
 * A reset token for the staff member, as the emailed link would carry.
 */
function staffResetToken(StoreUser $storeUser): string
{
    return Password::broker('store_users')->createToken($storeUser);
}

describe('requesting a reset link', function () {
    beforeEach(fn () => Notification::fake());

    test('the sign-in page links to it', function () {
        $this->get(route('portal.login'))
            ->assertOk()
            ->assertSee(route('portal.password.request'));
    });

    test('staff at this location are emailed a link that opens on this store\'s subdomain', function () {
        Livewire::test('pages::portal.forgot-password')
            ->set('email', 'Jordan@Example.com')
            ->call('sendResetLink')
            ->assertHasNoErrors()
            ->assertSee('we\'ve emailed it a link');

        $mail = null;
        Notification::assertSentTo($this->staff, StaffPasswordResetNotification::class, function ($notification) use (&$mail) {
            $mail = $notification->toMail($this->staff);

            return true;
        });

        expect($mail->subject)->toBe('Reset your Riverside Cremation staff portal password')
            ->and($mail->actionUrl)->toStartWith('https://riverside.'.config('app.root_domain').'/portal/reset-password/');
    });

    test('nobody is emailed for an address without a login at this location, and the page does not say so', function (Closure $email) {
        Livewire::test('pages::portal.forgot-password')
            ->set('email', $email())
            ->call('sendResetLink')
            ->assertHasNoErrors()
            ->assertSee('we\'ve emailed it a link');

        Notification::assertNothingSent();
    })->with([
        'unknown email' => [fn () => 'nobody@example.com'],
        'another store\'s staff' => [fn () => StoreUser::factory()->forStore(Store::factory()->create())->create()->email],
    ]);

    test('requests from one address are limited', function () {
        foreach (range(1, 5) as $attempt) {
            Livewire::test('pages::portal.forgot-password')->set('email', "person{$attempt}@example.com")->call('sendResetLink');
        }

        Livewire::test('pages::portal.forgot-password')
            ->set('email', 'jordan@example.com')
            ->call('sendResetLink')
            ->assertHasErrors('email');

        Notification::assertNothingSent();
    });

    test('the reset email escapes the names it shows', function () {
        $store = Store::factory()->create(['name' => '<script>alert(1)</script> Home']);
        $storeUser = StoreUser::factory()->forStore($store)->create(['name' => '<b>Jordan</b>']);

        $html = (string) (new StaffPasswordResetNotification($storeUser, $store, 'token'))->toMail($storeUser)->render();

        expect($html)->not->toContain('<script>alert(1)</script>')
            ->not->toContain('<b>Jordan</b>')
            ->toContain('&lt;b&gt;Jordan&lt;/b&gt;');
    });
});

describe('choosing a new password', function () {
    test('a valid link sets the new password and signs the staff member in', function () {
        $token = staffResetToken($this->staff);
        $sessionIdBeforeReset = Session::getId();

        Livewire::withQueryParams(['email' => 'jordan@example.com'])
            ->test('pages::portal.reset-password', ['token' => $token])
            ->assertSet('email', 'jordan@example.com')
            ->set('password', 'a-strong-new-password')
            ->set('password_confirmation', 'a-strong-new-password')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('portal.orders'));

        expect(Hash::check('a-strong-new-password', $this->staff->fresh()->password))->toBeTrue()
            ->and(Auth::guard('store')->id())->toBe($this->staff->id)
            ->and(Session::getId())->not->toBe($sessionIdBeforeReset)
            ->and(Password::broker('store_users')->tokenExists($this->staff->fresh(), $token))->toBeFalse();
    });

    test('a staff member who never accepted their invitation can use a reset link instead', function () {
        $invitee = StoreUser::factory()->forStore($this->store)->invited('invitation-token')->create(['email' => 'casey@example.com']);

        Livewire::test('pages::portal.reset-password', ['token' => staffResetToken($invitee)])
            ->set('email', 'casey@example.com')
            ->set('password', 'a-strong-new-password')
            ->set('password_confirmation', 'a-strong-new-password')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $invitee = $invitee->fresh();
        expect($invitee->hasAcceptedInvitation())->toBeTrue()
            ->and($invitee->hasValidInvitation('invitation-token'))->toBeFalse();
    });

    test('the new password must be confirmed', function () {
        $passwordBefore = $this->staff->password;

        Livewire::test('pages::portal.reset-password', ['token' => staffResetToken($this->staff)])
            ->set('email', 'jordan@example.com')
            ->set('password', 'a-strong-new-password')
            ->set('password_confirmation', 'something-else')
            ->call('resetPassword')
            ->assertHasErrors(['password' => 'confirmed']);

        expect($this->staff->fresh()->password)->toBe($passwordBefore);
    });

    test('an unusable link cannot set a password', function (Closure $tokenFor) {
        $token = $tokenFor($this->staff);
        $passwordBefore = $this->staff->fresh()->password;

        Livewire::test('pages::portal.reset-password', ['token' => $token])
            ->set('email', 'jordan@example.com')
            ->set('password', 'a-strong-new-password')
            ->set('password_confirmation', 'a-strong-new-password')
            ->call('resetPassword')
            ->assertHasErrors('email')
            ->assertSee('This reset link is invalid or has expired.');

        expect($this->staff->fresh()->password)->toBe($passwordBefore)
            ->and(Auth::guard('store')->check())->toBeFalse();
    })->with([
        'wrong token' => [fn () => 'guessed-token'],
        'expired' => [function (StoreUser $staff) {
            $token = staffResetToken($staff);
            test()->travel(config('auth.passwords.store_users.expire') + 1)->minutes();

            return $token;
        }],
        'already used' => [function (StoreUser $staff) {
            $token = staffResetToken($staff);
            Password::broker('store_users')->reset(
                ['email' => $staff->email, 'password' => 'first-new-password', 'token' => $token],
                fn (StoreUser $storeUser, string $password) => $storeUser->resetPassword($password),
            );

            return $token;
        }],
    ]);

    test('a link only works at locations the staff member has access to', function () {
        $token = staffResetToken($this->staff);
        actingAsTenant(Store::factory()->create());

        Livewire::test('pages::portal.reset-password', ['token' => $token])
            ->set('email', 'jordan@example.com')
            ->set('password', 'a-strong-new-password')
            ->set('password_confirmation', 'a-strong-new-password')
            ->call('resetPassword')
            ->assertHasErrors('email');

        expect(Hash::check('a-strong-new-password', $this->staff->fresh()->password))->toBeFalse();
    });
});
