<?php

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Store;
use App\Services\Cart;
use App\Services\CheckoutService;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->stripeConnected()->create(['tax_rate_bps' => 1000]);
    actingAsTenant($this->store);

    $this->certificates = Product::factory()->for($this->store)->category(ProductCategory::Service)->create([
        'name' => 'Death certificates',
        'price_cents' => 25000,
        'per_unit_price_cents' => 1500,
        'per_unit_label' => 'copy',
        'is_taxable' => false,
        'is_required' => true,
    ]);
});

test('a per-unit item charges the base fee once plus the unit price per quantity', function () {
    $cart = new Cart($this->store);
    $cart->addLine($this->certificates, null, 3);

    expect($cart->subtotalCents())->toBe(25000 + 3 * 1500)
        ->and($cart->taxCents())->toBe(0);
});

test('a taxable per-unit item taxes both the base fee and the units', function () {
    $this->certificates->update(['is_taxable' => true]);

    $cart = new Cart($this->store);
    $cart->addLine($this->certificates->fresh(), null, 2);

    expect($cart->taxableSubtotalCents())->toBe(28000)
        ->and($cart->taxCents())->toBe(2800);
});

test('the base fee is snapshotted on the order item', function () {
    $cart = new Cart($this->store);
    $cart->addLine($this->certificates, null, 3);

    $order = app(CheckoutService::class)->createOrder($this->store, $cart, [
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'relationship_to_deceased' => 'Adult child',
        'deceased_first_name' => 'Pat',
        'deceased_last_name' => 'Rivera',
    ]);

    $item = $order->items->first();

    expect($item->base_price_cents_snapshot)->toBe(25000)
        ->and($item->unit_price_cents)->toBe(1500)
        ->and($item->total_price_cents)->toBe(29500)
        ->and($order->subtotal_cents)->toBe(29500);
});

test('required items are pre-selected when the customer picks a timing', function () {
    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate');

    expect((new Cart($this->store))->allLines()->keys()->all())->toContain($this->certificates->id.'-0');
});

test('a required item cannot be removed', function () {
    $cart = new Cart($this->store);
    $cart->ensureRequiredLines();

    $key = $this->certificates->id.'-0';
    $cart->removeAny($key);
    $cart->updateLineQuantity($key, 0);

    $lines = (new Cart($this->store))->allLines();

    expect($lines->has($key))->toBeTrue()
        ->and($lines->get($key)['quantity'])->toBe(1);
});

test('the quantity of a required per-unit item can be raised but not below one', function () {
    $cart = new Cart($this->store);
    $cart->ensureRequiredLines();

    $key = $this->certificates->id.'-0';
    $cart->updateLineQuantity($key, 5);
    expect($cart->allLines()->get($key)['quantity'])->toBe(5);

    $cart->updateLineQuantity($key, 0);
    expect($cart->allLines()->get($key)['quantity'])->toBe(1);
});

test('ordinary items can still be removed', function () {
    $keepsake = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create();

    $cart = new Cart($this->store);
    $cart->addLine($keepsake);
    $cart->removeAny($keepsake->id.'-0');

    expect($cart->allLines()->has($keepsake->id.'-0'))->toBeFalse();
});

test('the price label describes per-unit pricing', function () {
    expect($this->certificates->priceLabel())->toBe('$250.00 + $15.00 per copy');
});
