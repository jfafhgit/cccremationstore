<?php

use App\Enums\PlatformFeeModel;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for the platform account's Stripe Billing API, recording every
 * request so tests can check what was sent.
 */
function fakeStripeBilling(): object
{
    $fake = new class implements ClientInterface
    {
        /** @var array<string, array<string, mixed>> */
        public array $checkoutSessions = [];

        /** @var array<string, array<string, mixed>> */
        public array $subscriptions = [];

        /** @var array<int, array{method: string, path: string, params: array<string, mixed>}> */
        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $path = parse_url($absUrl, PHP_URL_PATH);
            $params = is_array($params) ? $params : [];
            $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params];

            $body = match (true) {
                $path === '/v1/customers' => ['id' => 'cus_test', 'object' => 'customer'],
                $path === '/v1/checkout/sessions' => ['id' => 'cs_new', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_new'],
                $path === '/v1/billing_portal/sessions' => ['id' => 'bps_test', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test'],
                str_starts_with($path, '/v1/checkout/sessions/') => $this->checkoutSessions[basename($path)],
                str_starts_with($path, '/v1/subscriptions/') => $this->subscription(basename($path), $method === 'post' ? $params : []),
            };

            return [json_encode($body), 200, []];
        }

        private function subscription(string $id, array $changes): array
        {
            $subscription = $this->subscriptions[$id];

            if (isset($changes['cancel_at_period_end'])) {
                $subscription['cancel_at_period_end'] = (bool) $changes['cancel_at_period_end'];
            }

            if (isset($changes['items'][0]['price_data']['unit_amount'])) {
                $subscription['items']['data'][0]['price']['unit_amount'] = (int) $changes['items'][0]['price_data']['unit_amount'];
            }

            return $this->subscriptions[$id] = $subscription;
        }

        public function subscriptionFor(string $id, string $status, int $unitAmount, array $metadata = []): void
        {
            $this->subscriptions[$id] = [
                'id' => $id,
                'object' => 'subscription',
                'status' => $status,
                'cancel_at_period_end' => false,
                'metadata' => $metadata,
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_test',
                    'object' => 'subscription_item',
                    'price' => ['id' => 'price_test', 'object' => 'price', 'unit_amount' => $unitAmount, 'product' => 'prod_test'],
                ]]],
            ];
        }

        public function lastRequestTo(string $method, string $path): ?array
        {
            return collect($this->requests)->last(fn (array $request): bool => $request['method'] === $method && $request['path'] === $path);
        }
    };

    ApiRequestor::setHttpClient($fake);

    return $fake;
}

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_fake',
        'services.stripe.webhook_secret' => null,
        'services.stripe.platform_webhook_secret' => null,
    ]);

    $this->stripe = fakeStripeBilling();

    $this->store = Store::factory()->create([
        'platform_fee_model' => PlatformFeeModel::Subscription,
        'subscription_monthly_cents' => 9900,
    ]);
    actingAsTenant($this->store);

    $this->owner = StoreUser::factory()->for($this->store)->create();
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function subscribeStore(Store $store, string $status = 'active'): void
{
    $store->forceFill([
        'stripe_customer_id' => 'cus_test',
        'stripe_subscription_id' => 'sub_test',
        'subscription_status' => $status,
    ])->save();
}

test('subscription and no-fee stores take no platform fee from orders', function (PlatformFeeModel $model) {
    $this->store->update(['platform_fee_model' => $model, 'platform_fee_bps' => 500, 'platform_fee_flat_cents' => 2500]);

    expect($this->store->platformFeeCentsFor(100000))->toBe(0);
})->with([PlatformFeeModel::Subscription, PlatformFeeModel::None]);

test('the owner sets up billing through stripe checkout at the store monthly amount', function () {
    Auth::guard('store')->login($this->owner);

    Livewire::test('pages::portal.billing')
        ->assertSee('99.00')
        ->assertSee('Not set up')
        ->call('startSubscription')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_new');

    $params = $this->stripe->lastRequestTo('post', '/v1/checkout/sessions')['params'];

    expect($params['mode'])->toBe('subscription')
        ->and($params['customer'])->toBe('cus_test')
        ->and($params['client_reference_id'])->toBe((string) $this->store->id)
        ->and($params['line_items'][0]['price_data']['unit_amount'])->toBe(9900)
        ->and($params['line_items'][0]['price_data']['recurring']['interval'])->toBe('month')
        ->and($this->store->fresh()->stripe_customer_id)->toBe('cus_test');
});

test('staff who are not the owner cannot manage billing', function () {
    Auth::guard('store')->login(StoreUser::factory()->for($this->store)->staff()->create());

    Livewire::test('pages::portal.billing')
        ->assertSee('Only the account owner can manage billing')
        ->call('startSubscription')
        ->assertForbidden();

    expect($this->stripe->requests)->toBeEmpty();
});

test('returning from checkout records the subscription as active', function () {
    Auth::guard('store')->login($this->owner);
    $this->store->forceFill(['stripe_customer_id' => 'cus_test'])->save();
    $this->stripe->checkoutSessions['cs_done'] = [
        'id' => 'cs_done', 'object' => 'checkout.session', 'mode' => 'subscription', 'status' => 'complete',
        'client_reference_id' => (string) $this->store->id, 'customer' => 'cus_test', 'subscription' => 'sub_test',
    ];
    $this->stripe->subscriptionFor('sub_test', 'active', 9900);

    Livewire::withQueryParams(['checkout_session' => 'cs_done'])
        ->test('pages::portal.billing')
        ->assertSee('Active');

    expect($this->store->fresh())
        ->stripe_subscription_id->toBe('sub_test')
        ->subscription_status->toBe('active');
});

test('a checkout session belonging to another store is not recorded', function () {
    Auth::guard('store')->login($this->owner);
    $this->stripe->checkoutSessions['cs_other'] = [
        'id' => 'cs_other', 'object' => 'checkout.session', 'mode' => 'subscription', 'status' => 'complete',
        'client_reference_id' => '999999', 'customer' => 'cus_other', 'subscription' => 'sub_other',
    ];

    Livewire::withQueryParams(['checkout_session' => 'cs_other'])->test('pages::portal.billing');

    expect($this->store->fresh()->stripe_subscription_id)->toBeNull();
});

test('a failed renewal is flagged as past due in admin without stopping the store', function () {
    subscribeStore($this->store);
    $this->stripe->subscriptionFor('sub_test', 'past_due', 9900);

    $this->postJson(route('stripe.webhook'), [
        'id' => 'evt_test', 'object' => 'event', 'type' => 'customer.subscription.updated', 'account' => null,
        'data' => ['object' => ['id' => 'sub_test', 'object' => 'subscription', 'status' => 'past_due']],
    ])->assertOk();

    expect($this->store->fresh()->subscription_status)->toBe('past_due');

    $this->actingAs(User::factory()->create());
    Livewire::test('pages::admin.stores.index')->assertSee('Billing past due');

    expect($this->store->fresh()->status->value)->toBe($this->store->status->value);
});

test('subscription events from a connected account are ignored', function () {
    subscribeStore($this->store);

    $this->postJson(route('stripe.webhook'), [
        'id' => 'evt_test', 'object' => 'event', 'type' => 'customer.subscription.updated', 'account' => 'acct_funeral_home',
        'data' => ['object' => ['id' => 'sub_test', 'object' => 'subscription', 'status' => 'canceled']],
    ])->assertOk();

    expect($this->store->fresh()->subscription_status)->toBe('active')
        ->and($this->stripe->requests)->toBeEmpty();
});

test('changing the monthly amount in admin updates the subscription from the next bill', function () {
    subscribeStore($this->store);
    $this->stripe->subscriptionFor('sub_test', 'active', 9900);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.stores.show', ['store' => $this->store])
        ->set('subscriptionMonthly', '149.00')
        ->call('save')
        ->assertHasNoErrors();

    $params = $this->stripe->lastRequestTo('post', '/v1/subscriptions/sub_test')['params'];

    expect($params['items'][0]['price_data']['unit_amount'])->toBe(14900)
        ->and($params['items'][0]['price_data']['product'])->toBe('prod_test')
        ->and($params['proration_behavior'])->toBe('none')
        ->and($this->store->fresh()->subscription_monthly_cents)->toBe(14900);
});

test('moving a subscribed store to another fee model cancels at the end of the paid period', function () {
    subscribeStore($this->store);
    $this->stripe->subscriptionFor('sub_test', 'active', 9900);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.stores.show', ['store' => $this->store])
        ->set('platformFeeModel', PlatformFeeModel::Percentage->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->stripe->lastRequestTo('post', '/v1/subscriptions/sub_test')['params'])
        ->toBe(['cancel_at_period_end' => 'true']); // Stripe's wire format for booleans
});
