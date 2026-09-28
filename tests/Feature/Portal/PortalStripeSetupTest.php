<?php

use App\Enums\StoreUserRole;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Notifications\StripeSetupRequestNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

beforeEach(function () {
    config(['services.stripe.secret' => 'sk_test_fake']);

    $this->stripe = new class implements ClientInterface
    {
        /** @var array<int, array{method: string, url: string, params: array<string, mixed>}> */
        public array $requests = [];

        public array $account = [
            'id' => 'acct_new',
            'object' => 'account',
            'details_submitted' => true,
            'charges_enabled' => true,
            'payouts_enabled' => true,
        ];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

            if (str_contains($absUrl, '/v1/account_links')) {
                return [json_encode(['object' => 'account_link', 'url' => 'https://connect.stripe.com/setup/s/test']), 200, []];
            }

            return [json_encode($this->account), 200, []];
        }
    };

    ApiRequestor::setHttpClient($this->stripe);

    $this->store = Store::factory()->create();
    actingAsTenant($this->store);

    $this->owner = StoreUser::factory()->forStore($this->store)->create(['email' => 'owner@example.com']);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('the owner connects stripe from the portal with their own email pre-filled', function () {
    Auth::guard('store')->login($this->owner);

    Livewire::test('pages::portal.payments')
        ->assertSee('Not connected')
        ->call('connectStripe')
        ->assertRedirect('https://connect.stripe.com/setup/s/test');

    $createAccount = collect($this->stripe->requests)->first(fn ($request) => str_ends_with($request['url'], '/v1/accounts'));
    $accountLink = collect($this->stripe->requests)->first(fn ($request) => str_contains($request['url'], '/v1/account_links'));

    expect($createAccount['params']['email'])->toBe('owner@example.com')
        ->and($accountLink['params']['return_url'])->toBe(route('portal.payments', ['stripe' => 'return']))
        ->and($this->store->fresh()->stripe_account_id)->toBe('acct_new');
});

test('staff who are not the owner cannot start stripe setup', function () {
    Auth::guard('store')->login(StoreUser::factory()->forStore($this->store, StoreUserRole::Staff)->create());

    Livewire::test('pages::portal.payments')
        ->assertSee('Only the account owner can set up payments')
        ->call('connectStripe')
        ->assertForbidden();

    expect($this->stripe->requests)->toBeEmpty();
});

test('an owner of a different location cannot start stripe setup here', function () {
    Auth::guard('store')->login(StoreUser::factory()->forStore(Store::factory()->create())->forStore($this->store, StoreUserRole::Staff)->create());

    Livewire::test('pages::portal.payments')->call('connectStripe')->assertForbidden();
});

test('returning from stripe records the account status', function () {
    Auth::guard('store')->login($this->owner);
    $this->store->forceFill(['stripe_account_id' => 'acct_new'])->save();

    Livewire::withQueryParams(['stripe' => 'return'])
        ->test('pages::portal.payments')
        ->assertSee('Connected & accepting payments');

    expect($this->store->fresh()->isStripeReady())->toBeTrue();
});

describe('admin asking the owner to connect stripe', function () {
    beforeEach(function () {
        Notification::fake();
        $this->actingAs(User::factory()->create());
    });

    test('the email goes to owners who can sign in and links to the portal payments page', function () {
        $staff = StoreUser::factory()->forStore($this->store, StoreUserRole::Staff)->create();
        $pendingOwner = StoreUser::factory()->forStore($this->store)->invited()->create();

        Livewire::test('pages::admin.stores.show', ['store' => $this->store])->call('requestStripeSetup');

        Notification::assertSentTo($this->owner, StripeSetupRequestNotification::class, function ($notification) {
            return $notification->toMail($this->owner)->actionUrl === route('portal.payments', ['store' => $this->store->slug]);
        });
        Notification::assertNotSentTo([$staff, $pendingOwner], StripeSetupRequestNotification::class);
    });

    test('nothing is sent when the store has no owner who can sign in yet', function () {
        $this->owner->delete();

        Livewire::test('pages::admin.stores.show', ['store' => $this->store])->call('requestStripeSetup');

        Notification::assertNothingSent();
    });
});
