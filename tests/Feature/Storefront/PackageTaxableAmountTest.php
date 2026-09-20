<?php

use App\Enums\OrderSource;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Store;
use App\Services\Cart;
use App\Services\CheckoutService;

beforeEach(function () {
    $this->store = Store::factory()->stripeConnected()->create(['tax_rate_bps' => 1000]);
    actingAsTenant($this->store);
});

test('a package only taxes its taxable amount', function () {
    $package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create([
        'price_cents' => 200000,
        'is_taxable' => true,
        'taxable_amount_cents' => 30000,
    ]);

    $cart = new Cart($this->store);
    $cart->selectSlot($package);

    expect($cart->subtotalCents())->toBe(200000)
        ->and($cart->taxableSubtotalCents())->toBe(30000)
        ->and($cart->taxCents())->toBe(3000)
        ->and($cart->totalCents())->toBe(203000);
});

test('a package with no taxable amount uses the taxable flag for the full price', function () {
    $taxable = Product::factory()->for($this->store)->category(ProductCategory::Package)->create([
        'price_cents' => 100000,
        'is_taxable' => true,
    ]);
    $exempt = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create([
        'price_cents' => 5000,
        'is_taxable' => false,
    ]);

    $cart = new Cart($this->store);
    $cart->selectSlot($taxable);
    $cart->addLine($exempt);

    expect($cart->taxableSubtotalCents())->toBe(100000)
        ->and($cart->taxCents())->toBe(10000);
});

test('a zero taxable amount means the package is not taxed', function () {
    $package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create([
        'price_cents' => 100000,
        'is_taxable' => false,
        'taxable_amount_cents' => 0,
    ]);

    $cart = new Cart($this->store);
    $cart->selectSlot($package);

    expect($cart->taxCents())->toBe(0);
});

test('the taxable amount is snapshotted on the order item', function () {
    $package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create([
        'price_cents' => 200000,
        'is_taxable' => true,
        'taxable_amount_cents' => 30000,
    ]);

    $cart = new Cart($this->store);
    $cart->selectSlot($package);

    $order = app(CheckoutService::class)->createOrder($this->store, $cart, [
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'relationship_to_deceased' => 'Adult child',
        'deceased_first_name' => 'Pat',
        'deceased_last_name' => 'Rivera',
    ], OrderSource::Storefront);

    expect($order->tax_cents)->toBe(3000)
        ->and($order->items->first()->taxable_unit_cents_snapshot)->toBe(30000);
});

test('a cart line saved before taxable amounts existed is re-evaluated from its product', function () {
    $package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create([
        'price_cents' => 200000,
        'is_taxable' => true,
        'taxable_amount_cents' => 30000,
    ]);

    session()->put("cart.{$this->store->id}", [
        'timing' => 'immediate',
        'package' => [
            'product_id' => $package->id,
            'variant_id' => null,
            'category' => 'package',
            'name' => $package->name,
            'variant_name' => null,
            'image_path' => null,
            'is_taxable' => true,
            'unit_price_cents' => 200000,
            'quantity' => 1,
        ],
        'container' => null,
        'urn' => null,
        'lines' => [],
    ]);

    expect((new Cart($this->store))->taxCents())->toBe(3000);
});
