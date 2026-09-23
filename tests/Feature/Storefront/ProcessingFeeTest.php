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

    $this->package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create([
        'price_cents' => 100000,
        'is_taxable' => true,
    ]);
});

test('a store with the processing fee disabled charges nothing extra', function () {
    $cart = new Cart($this->store);
    $cart->selectSlot($this->package);

    expect($cart->processingFeeCents())->toBe(0)
        ->and($cart->totalCents())->toBe(110000); // subtotal 100000 + 10% tax
});

test('the processing fee is calculated on subtotal plus tax, not on the fee itself', function () {
    $this->store->update(['processing_fee_enabled' => true, 'processing_fee_bps' => 350]);

    $cart = new Cart($this->store);
    $cart->selectSlot($this->package);

    // subtotal 100000 + tax 10000 = 110000; 3.5% of that = 3850.
    expect($cart->taxCents())->toBe(10000)
        ->and($cart->processingFeeCents())->toBe(3850)
        ->and($cart->totalCents())->toBe(113850);
});

test('the processing fee is snapshotted on the order and included in the charged total', function () {
    $this->store->update(['processing_fee_enabled' => true, 'processing_fee_bps' => 350]);

    $cart = new Cart($this->store);
    $cart->selectSlot($this->package);

    $order = app(CheckoutService::class)->createOrder($this->store, $cart, [
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'relationship_to_deceased' => 'Adult child',
        'deceased_first_name' => 'Pat',
        'deceased_last_name' => 'Rivera',
    ], OrderSource::Storefront);

    expect($order->processing_fee_cents)->toBe(3850)
        ->and($order->total_cents)->toBe(113850);
});
