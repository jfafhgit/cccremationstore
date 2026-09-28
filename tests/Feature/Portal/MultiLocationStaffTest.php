<?php

use App\Enums\StoreUserRole;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Notifications\StoreStaffInvitationNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->north = Store::factory()->create(['name' => 'North Chapel', 'slug' => 'north']);
    $this->south = Store::factory()->create(['name' => 'South Chapel', 'slug' => 'south']);

    $this->storeUser = StoreUser::factory()
        ->forStore($this->north)
        ->forStore($this->south, StoreUserRole::Staff)
        ->create(['email' => 'jordan@example.com', 'password' => Hash::make('correct-password')]);
});

test('one login signs in at every location it has access to, with its role there', function () {
    foreach ([$this->north, $this->south] as $store) {
        actingAsTenant($store);

        Livewire::test('pages::portal.login')
            ->set('email', 'jordan@example.com')
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasNoErrors();

        expect(Auth::guard('store')->id())->toBe($this->storeUser->id);
        Auth::guard('store')->logout();
    }

    expect($this->storeUser->isOwnerOf($this->north))->toBeTrue()
        ->and($this->storeUser->isOwnerOf($this->south))->toBeFalse();
});

describe('admin managing access', function () {
    beforeEach(function () {
        Notification::fake();
        $this->actingAs(User::factory()->create());
        $this->west = Store::factory()->create();
    });

    test('adding an email that already has a login gives it access here without a new invitation', function () {
        Livewire::test('pages::admin.stores.show', ['store' => $this->west])
            ->set('staffName', 'Someone Else')
            ->set('staffEmail', 'jordan@example.com')
            ->set('staffRole', StoreUserRole::Owner->value)
            ->call('createStaffUser')
            ->assertHasNoErrors();

        expect(StoreUser::where('email', 'jordan@example.com')->count())->toBe(1)
            ->and($this->storeUser->isOwnerOf($this->west))->toBeTrue()
            ->and(Hash::check('correct-password', $this->storeUser->fresh()->password))->toBeTrue();

        Notification::assertNothingSent();
    });

    test('a login that has not chosen a password yet is invited to the new location', function () {
        $pending = StoreUser::factory()->forStore($this->north)->invited()->create();

        Livewire::test('pages::admin.stores.show', ['store' => $this->west])
            ->set('staffName', $pending->name)
            ->set('staffEmail', $pending->email)
            ->call('createStaffUser')
            ->assertHasNoErrors();

        Notification::assertSentTo($pending, StoreStaffInvitationNotification::class, function ($notification) use ($pending) {
            return str_starts_with($notification->toMail($pending)->actionUrl, 'https://'.$this->west->slug.'.');
        });
    });

    test('the same person cannot be added to a store twice', function () {
        Livewire::test('pages::admin.stores.show', ['store' => $this->north])
            ->set('staffName', 'Jordan')
            ->set('staffEmail', 'jordan@example.com')
            ->call('createStaffUser')
            ->assertHasErrors(['staffEmail' => 'This person already has access to this store.']);
    });

    test('removing someone from one location keeps their access to the others', function () {
        Livewire::test('pages::admin.stores.show', ['store' => $this->south])
            ->call('removeStaffUser', $this->storeUser->id);

        expect($this->storeUser->belongsToStore($this->south))->toBeFalse()
            ->and($this->storeUser->belongsToStore($this->north))->toBeTrue();
    });

    test('removing someone from their last location deletes the login', function () {
        $single = StoreUser::factory()->forStore($this->west)->create();

        Livewire::test('pages::admin.stores.show', ['store' => $this->west])
            ->call('removeStaffUser', $single->id);

        expect(StoreUser::find($single->id))->toBeNull();
    });
});

describe('portal access', function () {
    test('signed-out visitors are sent to that store\'s own sign-in page', function () {
        $this->get(route('portal.orders', ['store' => 'north']))
            ->assertRedirect(route('portal.login', ['store' => 'north']));
    });

    test('someone whose access was removed is signed out of that location', function () {
        $this->storeUser->stores()->detach($this->south);

        $this->actingAs($this->storeUser, 'store')
            ->get(route('portal.orders', ['store' => 'south']))
            ->assertRedirect(route('portal.login', ['store' => 'south']));

        $this->assertGuest('store');
    });

    test('the portal header shows the location logo', function () {
        Storage::fake('public');
        $this->north->update(['brand_logo_path' => 'logos/north.png']);

        $this->actingAs($this->storeUser, 'store')
            ->get(route('portal.orders', ['store' => 'north']))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url('logos/north.png'), escape: false)
            ->assertSee('Switch location');
    });
});

/**
 * Use the location switcher and return the handoff link it redirects to.
 */
function handoffUrlFrom(StoreUser $storeUser, string $from, string $to): string
{
    return test()->actingAs($storeUser, 'store')
        ->post(route('portal.switch-location', ['store' => $from, 'location' => $to]))
        ->assertRedirectContains('https://'.$to.'.')
        ->headers->get('Location');
}
describe('switching location', function () {

    test('the handoff link signs the person in at the other location once', function () {
        $handoffUrl = handoffUrlFrom($this->storeUser, 'north', 'south');

        $this->get($handoffUrl)->assertRedirect(route('portal.orders', ['store' => 'south']));
        $this->assertAuthenticatedAs($this->storeUser, 'store');

        $this->get($handoffUrl)->assertRedirect(route('portal.login', ['store' => 'south']));
    });

    test('a handoff link only works at the location it was made for', function () {
        $handoffUrl = handoffUrlFrom($this->storeUser, 'north', 'south');

        $this->get(str_replace('https://south.', 'https://north.', $handoffUrl))
            ->assertRedirect(route('portal.login', ['store' => 'north']));
    });

    test('nobody can switch to a location they do not have access to', function () {
        Store::factory()->create(['slug' => 'elsewhere']);

        $this->actingAs($this->storeUser, 'store')
            ->post(route('portal.switch-location', ['store' => 'north', 'location' => 'elsewhere']))
            ->assertNotFound();
    });
});
