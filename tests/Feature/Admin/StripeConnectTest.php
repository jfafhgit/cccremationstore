<?php

use App\Models\Store;
use App\Models\User;
use App\Services\StripeConnectService;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

beforeEach(function () {
    config(['services.stripe.secret' => 'sk_test_fake']);

    $this->actingAs(User::factory()->create());

    // What Stripe reports for the connected account; null means it no
    // longer exists (e.g. deleted from the Stripe dashboard).
    $this->stripeAccount = new class implements ClientInterface
    {
        public ?array $account = [
            'id' => 'acct_test123',
            'object' => 'account',
            'details_submitted' => true,
            'charges_enabled' => true,
            'payouts_enabled' => false,
        ];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if ($this->account === null) {
                return [json_encode(['error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'resource_missing',
                    'message' => 'No such account',
                ]]), 404, []];
            }

            return [json_encode($this->account), 200, []];
        }
    };

    ApiRequestor::setHttpClient($this->stripeAccount);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('creating a connected account saves its id on the store', function () {
    $store = Store::factory()->create();

    app(StripeConnectService::class)->ensureAccount($store);

    expect($store->fresh()->stripe_account_id)->toBe('acct_test123');
});

test('syncing account status saves the capability flags on the store', function () {
    $store = Store::factory()->stripeConnected()->create([
        'stripe_charges_enabled' => false,
        'stripe_payouts_enabled' => true,
    ]);

    app(StripeConnectService::class)->syncAccountStatus($store);

    $store->refresh();
    expect($store->stripe_charges_enabled)->toBeTrue()
        ->and($store->stripe_payouts_enabled)->toBeFalse()
        ->and($store->isStripeReady())->toBeTrue();
});

test('an admin can start over when stripe onboarding was never finished', function () {
    $this->stripeAccount->account = [...$this->stripeAccount->account, 'details_submitted' => false, 'charges_enabled' => false];
    $store = Store::factory()->create(['stripe_account_id' => 'acct_test123']);

    Livewire::test('pages::admin.stores.show', ['store' => $store->id])
        ->assertSee('Start over')
        ->call('resetStripeConnection')
        ->assertDontSee('Start over');

    expect($store->fresh()->stripe_account_id)->toBeNull();
});

test('starting over works when the account was already deleted in stripe', function () {
    $this->stripeAccount->account = null;
    $store = Store::factory()->create(['stripe_account_id' => 'acct_test123']);

    app(StripeConnectService::class)->resetUnfinishedAccount($store);

    expect($store->fresh()->stripe_account_id)->toBeNull();
});

test('an onboarded stripe account cannot be reset even if the store has not synced yet', function () {
    // Stripe says onboarding is done, but the store's cached flags are stale.
    $store = Store::factory()->create(['stripe_account_id' => 'acct_test123']);

    Livewire::test('pages::admin.stores.show', ['store' => $store->id])
        ->call('resetStripeConnection');

    $store->refresh();
    expect($store->stripe_account_id)->toBe('acct_test123')
        ->and($store->stripe_details_submitted)->toBeTrue();
});
