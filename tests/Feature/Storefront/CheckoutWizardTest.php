<?php

use App\Enums\ProductCategory;
use App\Enums\StorePath;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->stripeConnected()->create();
    actingAsTenant($this->store);

    $this->package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create([
        'price_cents' => 100000,
    ]);

    $this->keepsake = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create([
        'price_cents' => 5000,
    ]);
});

function fillMinimalDetails($component): void
{
    $component
        ->set('deceasedFirstName', 'Pat')
        ->set('deceasedLastName', 'Rivera')
        ->set('relationshipToDeceased', 'Adult child')
        ->set('purchaserFirstName', 'Sam')
        ->set('purchaserLastName', 'Rivera')
        ->set('purchaserEmail', 'sam@example.com')
        ->set('purchaserPhone', '555-0100');
}

test('the wizard walks timing, personalize, and details in order', function () {
    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    expect($component->get('step'))->toBe('timing');

    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $this->package->id);

    // The timing step's "Continue" must land on personalize, not skip
    // straight to details — this was a real bug caught during manual
    // verification (the button called goToDetails() directly).
    $component->call('goToPersonalize');
    expect($component->get('step'))->toBe('personalize');

    $component->call('goToDetails');
    expect($component->get('step'))->toBe('details');
});

test('a package must be selected before moving to details', function () {
    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    $component->call('selectTiming', 'immediate');
    $component->call('goToPersonalize');
    $component->call('goToDetails');

    $component->assertHasErrors('package');
    expect($component->get('step'))->toBe('personalize');
});

test('submitting details creates an order with the cart contents', function () {
    config(['services.stripe.secret' => null]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $this->package->id);
    $component->call('goToPersonalize');
    $component->call('setKeepsakeQty', $this->keepsake->id, null, 2);
    $component->call('goToDetails');
    fillMinimalDetails($component);
    $component->call('submitDetails');

    $order = Order::where('store_id', $this->store->id)->first();

    expect($order)->not->toBeNull()
        ->and($order->status->value)->toBe('pending_payment')
        ->and($order->purchaser_email)->toBe('sam@example.com')
        ->and($order->deceased_first_name)->toBe('Pat')
        ->and($order->subtotal_cents)->toBe(100000 + 2 * 5000)
        ->and($order->items()->count())->toBe(2);
});

test('a failed payment attempt shows a friendly error and keeps the order for retry', function () {
    // No platform Stripe key configured — createPaymentIntent() must fail
    // gracefully rather than crashing with an uncaught SDK exception, and
    // the wizard should stay put so the family can try again.
    config(['services.stripe.secret' => null]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $this->package->id);
    $component->call('goToPersonalize');
    $component->call('goToDetails');
    fillMinimalDetails($component);
    $component->call('submitDetails');

    expect($component->get('paymentError'))->not->toBeNull()
        ->and($component->get('step'))->toBe('details');

    expect(Order::count())->toBe(1);

    // Retrying must not create a second order for the same attempt.
    $component->call('submitDetails');
    expect(Order::count())->toBe(1);
});

test('a store with no Stripe account shows a friendly message instead of attempting payment', function () {
    $unconnectedStore = Store::factory()->create(); // no stripe_account_id
    actingAsTenant($unconnectedStore);
    $package = Product::factory()->for($unconnectedStore)->category(ProductCategory::Package)->create();

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $package->id);
    $component->call('goToPersonalize');
    $component->call('goToDetails');
    fillMinimalDetails($component);
    $component->call('submitDetails');

    expect($component->get('paymentError'))->not->toBeNull();
    expect(Order::count())->toBe(0); // never even attempted to create an order
});

test('keepsake quantities in the cart survive a fresh mount of the component', function () {
    $first = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $first->call('setKeepsakeQty', $this->keepsake->id, null, 3);

    $second = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    expect($second->get('keepsakeQty'))->toBe(["{$this->keepsake->id}-0" => 3]);
});

test('an a la carte store applies its base package automatically', function () {
    $this->store->update(['checkout_path' => StorePath::ALaCarte]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    $component->call('selectTiming', 'immediate');

    expect($component->get('packageId'))->toBe($this->package->id);

    $component->call('goToPersonalize')->call('goToDetails');

    $component->assertHasNoErrors();
    expect($component->get('step'))->toBe('details');
});

test('an a la carte store cannot switch to another package', function () {
    $this->store->update(['checkout_path' => StorePath::ALaCarte]);
    $other = Product::factory()->for($this->store)->category(ProductCategory::Package)->create();

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    $component->call('selectTiming', 'immediate')->call('selectPackage', $other->id);

    expect($component->get('packageId'))->toBe($this->package->id);
});

test('a packages store lets the customer choose any package', function () {
    $other = Product::factory()->for($this->store)->category(ProductCategory::Package)->create();

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    $component->call('selectTiming', 'immediate')->call('selectPackage', $other->id);

    expect($component->get('packageId'))->toBe($other->id);
});

test('included items are listed on the package card', function () {
    $this->package->update(['included_items' => ['Basic container', 'Cremation permit']]);

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->assertSeeInOrder(['Basic container', 'Cremation permit']);
});
