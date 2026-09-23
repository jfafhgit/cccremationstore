<?php

use App\Enums\OrderStatus;
use App\Enums\ProductCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Notifications\OrderPaidNotification;
use App\Services\Cart;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's HTTP API so the real SDK code paths run, while
 * letting each test decide what state a PaymentIntent is in.
 */
function fakeStripe(): object
{
    $fake = new class implements ClientInterface
    {
        /** @var array<string, array<string, mixed>> */
        public array $intents = [];

        /** @var array<int, array{method: string, path: string, params: array<string, mixed>}> */
        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $path = parse_url($absUrl, PHP_URL_PATH);
            $params = is_array($params) ? $params : [];
            $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params];

            if ($method === 'post' && $path === '/v1/payment_intents') {
                $id = 'pi_'.count($this->intents);
                $this->intents[$id] = [
                    'id' => $id,
                    'object' => 'payment_intent',
                    'status' => 'requires_payment_method',
                    'client_secret' => "{$id}_secret",
                    'amount_received' => 0,
                    ...$params,
                ];
            } else {
                $id = basename($path);

                if ($method === 'post') {
                    $this->intents[$id] = [...$this->intents[$id], ...$params];
                }
            }

            return [json_encode($this->intents[$id]), 200, []];
        }

        public function intent(string $id, array $attributes): void
        {
            $this->intents[$id] = [
                'id' => $id,
                'object' => 'payment_intent',
                'currency' => 'usd',
                'client_secret' => "{$id}_secret",
                'amount_received' => 0,
                ...$attributes,
            ];
        }

        public function updatesTo(string $id): array
        {
            return array_values(array_filter(
                $this->requests,
                fn (array $request): bool => $request['method'] === 'post' && $request['path'] === "/v1/payment_intents/{$id}",
            ));
        }
    };

    ApiRequestor::setHttpClient($fake);

    return $fake;
}

function stripeEvent(string $type, string $account, array $object): array
{
    return [
        'id' => 'evt_test',
        'object' => 'event',
        'type' => $type,
        'account' => $account,
        'data' => ['object' => $object],
    ];
}

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_fake',
        'services.stripe.webhook_secret' => null,
    ]);

    Notification::fake();

    $this->stripe = fakeStripe();

    $this->store = Store::factory()->stripeConnected()->create();
    actingAsTenant($this->store);

    $this->order = Order::factory()->for($this->store)->create([
        'total_cents' => 150000,
        'stripe_account_id' => $this->store->stripe_account_id,
        'stripe_payment_intent_id' => 'pi_existing',
    ]);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('changing the cart after starting payment updates the same order and its payment intent', function () {
    $package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create(['price_cents' => 100000]);
    $keepsake = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create(['price_cents' => 5000]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $package->id);
    $component->call('goToContainers');
    $component->call('goToAddons');
    $component->call('goToKeepsakes');
    $component->call('goToDetails');
    $component
        ->set('deceasedFirstName', 'Pat')
        ->set('deceasedLastName', 'Rivera')
        ->set('relationshipToDeceased', 'Adult child')
        ->set('purchaserFirstName', 'Sam')
        ->set('purchaserLastName', 'Rivera')
        ->set('purchaserEmail', 'sam@example.com')
        ->set('purchaserPhone', '555-0100');
    $component->call('submitDetails');

    $order = Order::findOrFail($component->get('orderId'));
    $intentId = $order->stripe_payment_intent_id;
    expect($this->stripe->intents[$intentId]['amount'])->toBe(100000);

    $component->call('backTo', 'keepsakes');
    $component->call('setKeepsakeQty', $keepsake->id, null, 2);
    $component->call('goToDetails');
    $component->set('purchaserEmail', 'corrected@example.com');
    $component->call('submitDetails');

    $order->refresh();

    expect(Order::whereKeyNot($this->order->id)->count())->toBe(1)
        ->and($component->get('step'))->toBe('payment')
        ->and($order->total_cents)->toBe(110000)
        ->and($order->purchaser_email)->toBe('corrected@example.com')
        ->and($order->items()->count())->toBe(2)
        ->and($order->stripe_payment_intent_id)->toBe($intentId)
        ->and($this->stripe->intents[$intentId]['amount'])->toBe(110000)
        ->and($this->stripe->intents[$intentId]['receipt_email'])->toBe('corrected@example.com');
});

test('a payment intent that already succeeded is never replaced with a new charge', function () {
    $this->stripe->intent('pi_existing', ['status' => 'succeeded', 'amount' => 150000]);

    app(CheckoutService::class)->createPaymentIntent($this->order);
})->throws(RuntimeException::class);

test('returning from a successful payment marks the order paid, emails once, and empties the cart', function () {
    $this->stripe->intent('pi_existing', ['status' => 'succeeded', 'amount' => 150000, 'amount_received' => 150000]);

    $cart = new Cart($this->store);
    $cart->addLine(Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create());
    $cart->setPendingOrderId($this->order->id);

    Livewire::test('pages::storefront.order-details', ['order' => $this->order->id])
        ->assertSee('is complete');
    Livewire::test('pages::storefront.order-details', ['order' => $this->order->id]);

    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->order->fresh()->paid_at)->not->toBeNull()
        ->and((new Cart($this->store))->isEmpty())->toBeTrue();

    Notification::assertSentTimes(OrderPaidNotification::class, 1);
});

test('the return page does not claim payment when stripe has not received it', function () {
    $this->stripe->intent('pi_existing', ['status' => 'requires_payment_method', 'amount' => 150000]);

    Livewire::test('pages::storefront.order-details', ['order' => $this->order->id])
        ->assertSee('received your payment yet')
        ->assertDontSee('is complete');

    expect($this->order->fresh()->paid_at)->toBeNull();
    Notification::assertNothingSent();
});

test('the return page accepts the query parameters stripe appends to the signed link', function () {
    $this->stripe->intent('pi_existing', ['status' => 'succeeded', 'amount' => 150000, 'amount_received' => 150000]);

    $this->get($this->order->detailsUrl().'&payment_intent=pi_existing&payment_intent_client_secret=pi_existing_secret&redirect_status=succeeded')
        ->assertOk();

    expect($this->order->fresh()->paid_at)->not->toBeNull();
});

test('a payment smaller than the order total does not mark the order paid', function () {
    $this->stripe->intent('pi_existing', ['status' => 'succeeded', 'amount' => 100, 'amount_received' => 100]);

    app(CheckoutService::class)->syncPaymentStatus($this->order);

    expect($this->order->fresh()->paid_at)->toBeNull();
});

test('the payment succeeded webhook marks the order paid only once', function () {
    $this->stripe->intent('pi_existing', ['status' => 'succeeded', 'amount' => 150000, 'amount_received' => 150000]);

    $event = stripeEvent('payment_intent.succeeded', $this->store->stripe_account_id, $this->stripe->intents['pi_existing']);

    $this->postJson(route('stripe.webhook'), $event)->assertOk();
    $this->postJson(route('stripe.webhook'), $event)->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid);
    Notification::assertSentTimes(OrderPaidNotification::class, 1);
});

test('the webhook re-checks the payment with stripe rather than trusting the payload', function () {
    $this->stripe->intent('pi_existing', ['status' => 'requires_payment_method', 'amount' => 150000]);

    $forged = stripeEvent('payment_intent.succeeded', $this->store->stripe_account_id, [
        'id' => 'pi_existing',
        'object' => 'payment_intent',
        'status' => 'succeeded',
    ]);

    $this->postJson(route('stripe.webhook'), $forged)->assertOk();

    expect($this->order->fresh()->paid_at)->toBeNull();
});

test('the webhook ignores payment events from a different connected account', function () {
    $this->stripe->intent('pi_existing', ['status' => 'succeeded', 'amount' => 150000, 'amount_received' => 150000]);

    $this->postJson(route('stripe.webhook'), stripeEvent('payment_intent.succeeded', 'acct_someone_else', $this->stripe->intents['pi_existing']))
        ->assertOk();

    expect($this->order->fresh()->paid_at)->toBeNull();
});

test('the webhook rejects unsigned events outside local development', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->postJson(route('stripe.webhook'), stripeEvent('payment_intent.succeeded', $this->store->stripe_account_id, ['id' => 'pi_existing', 'object' => 'payment_intent']))
        ->assertServerError();

    expect($this->stripe->requests)->toBeEmpty();
});

test('a full refund from the stripe dashboard marks the order refunded', function () {
    $this->order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);

    $this->postJson(route('stripe.webhook'), stripeEvent('charge.refunded', $this->store->stripe_account_id, [
        'id' => 'ch_test',
        'object' => 'charge',
        'payment_intent' => 'pi_existing',
        'refunded' => true,
    ]))->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Refunded);
});
