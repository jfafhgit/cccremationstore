<?php

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Cart;
use App\Services\CheckoutService;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->stripeConnected()->create(['tax_rate_bps' => 0]);
    actingAsTenant($this->store);

    $this->basic = Product::factory()->for($this->store)->create(['name' => 'Basic', 'price_cents' => 100000]);
    $this->standard = Product::factory()->for($this->store)->create(['name' => 'Standard', 'price_cents' => 200000]);
    $this->premium = Product::factory()->for($this->store)->create(['name' => 'Premium', 'price_cents' => 300000]);

    $this->care = Product::factory()->for($this->store)->category(ProductCategory::Choice)->create([
        'name' => 'Care of Deceased',
        'price_cents' => 0,
        'is_taxable' => false,
        'is_required' => true,
    ]);
    $this->refrigeration = ProductVariant::factory()->for($this->care)->create(['name' => 'Refrigeration', 'price_delta_cents' => 25000, 'sort_order' => 0]);
    $this->embalming = ProductVariant::factory()->for($this->care)->create(['name' => 'Embalming', 'price_delta_cents' => 60000, 'sort_order' => 1]);

    $this->standard->includedProducts()->attach($this->care->id, ['included_quantity' => 1, 'included_variant_id' => $this->refrigeration->id]);
    $this->premium->includedProducts()->attach($this->care->id, ['included_quantity' => 1, 'included_variant_id' => $this->embalming->id]);

    $this->refrigerationKey = $this->care->id.'-'.$this->refrigeration->id;
    $this->embalmingKey = $this->care->id.'-'.$this->embalming->id;
});

function addonsStepFor(Product $package)
{
    return Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $package->id)
        ->call('goToContainers')
        ->call('goToAddons');
}

test('without an included option the choose-one item works as before', function () {
    $component = addonsStepFor($this->basic)
        ->assertSee('$250.00')
        ->assertSee('$600.00');

    expect($component->instance()->extras()->firstWhere('id', $this->care->id)->variants->pluck('id')->all())
        ->toBe([$this->refrigeration->id, $this->embalming->id])
        ->and((new Cart($this->store))->selectedVariantId($this->care->id))->toBeNull();
});

test('an included option is selected for the family, with upgrades priced at the difference', function () {
    $component = addonsStepFor($this->standard)
        ->assertSee('Upgrade available')
        ->assertSee('+$350.00');

    expect((new Cart($this->store))->selectedVariantId($this->care->id))->toBe($this->refrigeration->id);

    $component->call('selectExtraOption', $this->care->id, $this->embalming->id)
        ->call('goToKeepsakes')
        ->assertHasNoErrors();

    $cart = new Cart($this->store);

    expect($cart->lineTotalCents($cart->allLines()->get($this->embalmingKey)))->toBe(35000)
        ->and($cart->subtotalCents())->toBe(200000 + 35000);
});

test('the family cannot decline or remove the option a package includes, and removing an upgrade goes back to it', function () {
    addonsStepFor($this->standard)
        ->call('selectExtraOption', $this->care->id, null)
        ->assertDontSee('No thanks');

    $cart = new Cart($this->store);
    expect($cart->selectedVariantId($this->care->id))->toBe($this->refrigeration->id);

    $cart->removeAny($this->refrigerationKey);
    expect($cart->selectedVariantId($this->care->id))->toBe($this->refrigeration->id)
        ->and($cart->lineTotalCents($cart->allLines()->get($this->refrigerationKey)))->toBe(0);

    $cart->selectOption($this->care, $this->embalming);
    $cart->removeAny($this->embalmingKey);
    expect($cart->selectedVariantId($this->care->id))->toBe($this->refrigeration->id);
});

test('an included option with nothing to upgrade to is hidden but still on the order', function () {
    $component = addonsStepFor($this->premium);

    expect($component->instance()->extras()->pluck('id'))->not->toContain($this->care->id);

    $order = app(CheckoutService::class)->createOrder($this->store, new Cart($this->store), [
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'relationship_to_deceased' => 'Adult child',
        'deceased_first_name' => 'Pat',
        'deceased_last_name' => 'Rivera',
    ]);

    $careItem = $order->items->firstWhere('product_id', $this->care->id);

    expect($careItem->variant_snapshot)->toBe('Embalming')
        ->and($careItem->total_price_cents)->toBe(0)
        ->and($careItem->discountLabel())->toBe('Included with package');
});

test('the package card lists the included option', function () {
    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->assertSee('Care of Deceased (Refrigeration)')
        ->assertSee('Care of Deceased (Embalming)');
});

test('switching packages keeps an upgrade the family chose but drops an option the package chose', function () {
    $cart = new Cart($this->store);

    $cart->selectSlot($this->standard);
    $cart->selectSlot($this->basic);
    expect($cart->selectedVariantId($this->care->id))->toBeNull();

    $cart->selectSlot($this->standard);
    $cart->selectOption($this->care, $this->embalming);
    $cart->selectSlot($this->basic);
    expect($cart->selectedVariantId($this->care->id))->toBe($this->embalming->id)
        ->and($cart->lineTotalCents($cart->allLines()->get($this->embalmingKey)))->toBe(60000);

    $cart->selectSlot($this->premium);
    expect($cart->lineTotalCents($cart->allLines()->get($this->embalmingKey)))->toBe(0);
});
