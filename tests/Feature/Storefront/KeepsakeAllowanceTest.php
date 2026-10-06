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

    $this->package = Product::factory()->for($this->store)->create(['name' => 'Legacy', 'price_cents' => 300000, 'is_taxable' => false, 'taxable_amount_cents' => 0]);
    $this->legacyTouch = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create([
        'name' => 'Legacy Touch Allowance',
        'price_cents' => 30000,
        'keepsake_allowance_cents' => 30000,
        'is_taxable' => false,
    ]);
    $this->package->includedProducts()->attach($this->legacyTouch->id, ['included_quantity' => 1]);

    $this->pendant = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create(['price_cents' => 20000, 'is_taxable' => true]);
    $this->bracelet = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create(['price_cents' => 15000, 'is_taxable' => true]);
    $this->certificates = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create(['price_cents' => 5000, 'is_taxable' => false]);

    $this->cart = new Cart($this->store);
    $this->cart->selectSlot($this->package);
});

test('the allowance is spread across keepsakes until it runs out, and is still taxed on the keepsakes', function () {
    $this->cart->addLine($this->pendant);
    $this->cart->addLine($this->bracelet);
    $this->cart->addLine($this->certificates);

    $lines = $this->cart->allLines();

    expect($this->cart->lineTotalCents($lines->get($this->pendant->id.'-0')))->toBe(0)
        ->and($this->cart->lineTotalCents($lines->get($this->bracelet->id.'-0')))->toBe(5000)
        ->and($this->cart->lineTotalCents($lines->get($this->certificates->id.'-0')))->toBe(5000)
        ->and($this->cart->keepsakeAllowanceRemainingCents())->toBe(0)
        ->and($this->cart->subtotalCents())->toBe(300000 + 5000 + 5000)
        ->and($this->cart->taxCents())->toBe(3500);
});

test('removing a keepsake frees up its share of the allowance', function () {
    $this->cart->addLine($this->pendant);
    $this->cart->addLine($this->bracelet);

    $this->cart->removeLine($this->pendant->id.'-0');

    expect($this->cart->lineTotalCents($this->cart->allLines()->get($this->bracelet->id.'-0')))->toBe(0)
        ->and($this->cart->keepsakeAllowanceRemainingCents())->toBe(15000);
});

test('the allowance only applies while its product is in the cart', function () {
    $otherPackage = Product::factory()->for($this->store)->create(['price_cents' => 100000]);
    $this->cart->addLine($this->pendant);

    $this->cart->selectSlot($otherPackage);

    expect($this->cart->keepsakeAllowanceCents())->toBe(0)
        ->and($this->cart->lineTotalCents($this->cart->allLines()->get($this->pendant->id.'-0')))->toBe(20000);
});

test('the keepsakes step shows how much of the allowance is left', function () {
    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->set('step', 'keepsakes')
        ->assertSeeInOrder(['Legacy Touch Allowance', '$300.00 left', 'of $300.00'])
        ->call('setKeepsakeQty', $this->pendant->id, null, 1)
        ->assertSee('$100.00 left');
});

test('the order records the allowance under the allowance product name', function () {
    $this->cart->addLine($this->pendant);

    $order = app(CheckoutService::class)->createOrder($this->store, $this->cart, [
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'relationship_to_deceased' => 'Adult child',
        'deceased_first_name' => 'Pat',
        'deceased_last_name' => 'Rivera',
    ]);

    $pendantItem = $order->items->firstWhere('product_id', $this->pendant->id);

    expect($pendantItem->allowance_cents)->toBe(20000)
        ->and($pendantItem->total_price_cents)->toBe(0)
        ->and($pendantItem->discountLabel())->toBe('Legacy Touch Allowance');
});

test('an allowance product is not offered on the addons step', function () {
    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->set('step', 'addons');

    expect($component->instance()->extras()->pluck('id')->all())->toBe([$this->certificates->id]);
});

test('an allowance set after the package was chosen applies once the package is chosen again', function () {
    $this->legacyTouch->update(['keepsake_allowance_cents' => 50000]);

    $this->cart->selectSlot($this->package);

    expect($this->cart->keepsakeAllowanceCents())->toBe(50000);
});
