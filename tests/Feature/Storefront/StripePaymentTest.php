<?php

use App\Enums\OrderStatus;
use App\Enums\ProductCategory;
use App\Enums\StoreUserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreUser;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderPaidNotification;
use App\Notifications\RefundIssuedNotification;
use App\Services\Cart;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Stripe\ApiRequestor;

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

test('a new payment intent accepts cards only', function () {
    $this->order->update(['stripe_payment_intent_id' => null]);

    app(CheckoutService::class)->createPaymentIntent($this->order);

    $params = $this->stripe->intents['pi_0'];
    expect($params['payment_method_types'])->toBe(['card'])
        ->and($params)->not->toHaveKey('automatic_payment_methods');
});

test('a payment intent started before cards-only is switched to cards only', function () {
    $this->stripe->intent('pi_existing', [
        'status' => 'requires_payment_method',
        'amount' => 150000,
        'application_fee_amount' => $this->order->platform_fee_cents,
        'receipt_email' => $this->order->purchaser_email,
        'payment_method_types' => ['card', 'cashapp', 'us_bank_account'],
    ]);

    app(CheckoutService::class)->createPaymentIntent($this->order);

    expect($this->stripe->updatesTo('pi_existing'))->toHaveCount(1)
        ->and($this->stripe->intents['pi_existing']['payment_method_types'])->toBe(['card']);
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

test('a paid order alerts the store staff who can sign in and the main email, once', function () {
    $this->stripe->intent('pi_existing', ['status' => 'succeeded', 'amount' => 150000, 'amount_received' => 150000]);
    $owner = StoreUser::factory()->forStore($this->store)->create();
    $staff = StoreUser::factory()->forStore($this->store, StoreUserRole::Staff)->create();
    $pending = StoreUser::factory()->forStore($this->store)->invited()->create();
    $otherStoreStaff = StoreUser::factory()->forStore(Store::factory()->create())->create();

    app(CheckoutService::class)->syncPaymentStatus($this->order);
    app(CheckoutService::class)->syncPaymentStatus($this->order->fresh());

    Notification::assertSentToTimes($owner, NewOrderNotification::class, 1);
    Notification::assertSentToTimes($staff, NewOrderNotification::class, 1);
    Notification::assertNotSentTo([$pending, $otherStoreStaff], NewOrderNotification::class);
    Notification::assertSentOnDemandTimes(NewOrderNotification::class, 1);
});

test('a store with no staff who can sign in gets the order alert at its main email', function () {
    $this->stripe->intent('pi_existing', ['status' => 'succeeded', 'amount' => 150000, 'amount_received' => 150000]);
    $this->store->update(['general_email' => 'office@funeralhome.test', 'contact_email' => 'billing@funeralhome.test']);

    app(CheckoutService::class)->syncPaymentStatus($this->order);

    Notification::assertSentOnDemand(NewOrderNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'office@funeralhome.test');
});

test('the main email is not alerted twice when a staff login uses the same address', function () {
    $this->stripe->intent('pi_existing', ['status' => 'succeeded', 'amount' => 150000, 'amount_received' => 150000]);
    $owner = StoreUser::factory()->forStore($this->store)->create(['email' => 'office@funeralhome.test']);
    $this->store->update(['general_email' => 'Office@FuneralHome.test']);

    app(CheckoutService::class)->syncPaymentStatus($this->order);

    Notification::assertSentToTimes($owner, NewOrderNotification::class, 1);
    Notification::assertSentOnDemandTimes(NewOrderNotification::class, 0);
});

test('the contact email never gets order alerts', function () {
    $this->stripe->intent('pi_existing', ['status' => 'succeeded', 'amount' => 150000, 'amount_received' => 150000]);
    $this->store->update(['general_email' => null, 'contact_email' => 'billing@funeralhome.test']);

    app(CheckoutService::class)->syncPaymentStatus($this->order);

    Notification::assertSentOnDemandTimes(NewOrderNotification::class, 0);
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

test('refunds from the stripe dashboard are recorded, and a full one marks the order refunded', function (int $refundedCents, OrderStatus $expectedStatus) {
    $this->order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    $this->stripe->refund('re_dashboard', 'pi_existing', $refundedCents);

    $this->postJson(route('stripe.webhook'), stripeEvent('charge.refunded', $this->store->stripe_account_id, [
        'id' => 'ch_test',
        'object' => 'charge',
        'payment_intent' => 'pi_existing',
    ]))->assertOk();

    $order = $this->order->fresh();

    expect($order->status)->toBe($expectedStatus)
        ->and($order->refunds()->sole())
        ->stripe_refund_id->toBe('re_dashboard')
        ->amount_cents->toBe($refundedCents)
        ->refunded_by_user_id->toBeNull();

    Notification::assertSentToTimes($this->order, RefundIssuedNotification::class, 1);
})->with([
    'full' => [150000, OrderStatus::Refunded],
    'partial' => [50000, OrderStatus::Paid],
]);

test('a refund that later fails no longer counts against the order', function () {
    $this->order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    $this->stripe->refund('re_dashboard', 'pi_existing', 50000, 'failed');

    $this->postJson(route('stripe.webhook'), stripeEvent('charge.refund.updated', $this->store->stripe_account_id, [
        'id' => 're_dashboard',
        'object' => 'refund',
        'payment_intent' => 'pi_existing',
    ]))->assertOk();

    expect($this->order->fresh()->refundableCents())->toBe(150000);
});

test('the payment step lists everything in the cart alongside the payment form', function () {
    $package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create(['name' => 'Direct Cremation', 'price_cents' => 100000]);
    $keepsake = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create(['name' => 'Fingerprint Pendant', 'price_cents' => 5000]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $package->id)
        ->call('goToContainers')
        ->call('goToAddons')
        ->call('goToKeepsakes')
        ->call('setKeepsakeQty', $keepsake->id, null, 2)
        ->call('goToDetails')
        ->set('deceasedFirstName', 'Pat')
        ->set('deceasedLastName', 'Rivera')
        ->set('relationshipToDeceased', 'Adult child')
        ->set('purchaserFirstName', 'Sam')
        ->set('purchaserLastName', 'Rivera')
        ->set('purchaserEmail', 'sam@example.com')
        ->set('purchaserPhone', '555-0100')
        ->call('submitDetails');

    $component->assertSet('step', 'payment')
        ->assertSeeInOrder(['Your order', 'Direct Cremation', '$1,000.00', 'Fingerprint Pendant', 'Qty 2', '$100.00', 'Total due today', '$1,100.00'])
        ->assertSeeHtml('id="payment-element"');
});
