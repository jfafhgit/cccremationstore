<?php

use App\Enums\PlatformFeeModel;
use App\Enums\ProductCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Cart;
use App\Services\CheckoutService;
use Livewire\Livewire;

beforeEach(function () {
    // 10% tax and a 3.5% processing fee, so the tests can prove the platform
    // fee ignores both and is based on the subtotal alone.
    $this->store = Store::factory()->stripeConnected()->create([
        'tax_rate_bps' => 1000,
        'processing_fee_enabled' => true,
        'processing_fee_bps' => 350,
    ]);
    actingAsTenant($this->store);

    $this->package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create([
        'price_cents' => 100000,
        'is_taxable' => true,
    ]);
});

function orderFor(Store $store, Product $package): Order
{
    $cart = new Cart($store);
    $cart->selectSlot($package);

    return app(CheckoutService::class)->createOrder($store, $cart, [
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'relationship_to_deceased' => 'Adult child',
        'deceased_first_name' => 'Pat',
        'deceased_last_name' => 'Rivera',
    ]);
}

test('a percentage fee is taken from the subtotal only', function () {
    $this->store->update(['platform_fee_model' => PlatformFeeModel::Percentage, 'platform_fee_bps' => 500]);

    expect(orderFor($this->store, $this->package)->platform_fee_cents)->toBe(5000); // 5% of $1,000.00
});

test('a flat fee is the same on every order regardless of its size', function () {
    $this->store->update(['platform_fee_model' => PlatformFeeModel::FlatPerOrder, 'platform_fee_flat_cents' => 2500]);

    expect(orderFor($this->store, $this->package)->platform_fee_cents)->toBe(2500);
});

test('a flat fee never exceeds the order subtotal', function () {
    $this->store->update(['platform_fee_model' => PlatformFeeModel::FlatPerOrder, 'platform_fee_flat_cents' => 2500]);
    $cheapPackage = Product::factory()->for($this->store)->category(ProductCategory::Package)->create(['price_cents' => 1000]);

    expect(orderFor($this->store, $cheapPackage)->platform_fee_cents)->toBe(1000);
});

test('an admin sets the platform fee in percent or dollars rather than basis points', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.stores.show', ['store' => $this->store])
        ->assertSet('platformFeePercent', '5.00')
        ->set('platformFeePercent', '2.75')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->store->fresh()->platform_fee_bps)->toBe(275);

    Livewire::test('pages::admin.stores.show', ['store' => $this->store->fresh()])
        ->set('platformFeeModel', PlatformFeeModel::FlatPerOrder->value)
        ->set('platformFeeFlat', '49.99')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->store->fresh())
        ->platform_fee_model->toBe(PlatformFeeModel::FlatPerOrder)
        ->platform_fee_flat_cents->toBe(4999);
});
