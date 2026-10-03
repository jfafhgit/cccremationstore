<?php

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Cart;
use App\Services\CheckoutService;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->stripeConnected()->create();
    actingAsTenant($this->store);

    $this->package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create(['price_cents' => 100000]);

    $this->handling = Product::factory()->for($this->store)->category(ProductCategory::Choice)->create([
        'name' => 'Handling of cremated remains',
        'price_cents' => 0,
        'is_taxable' => false,
        'is_required' => true,
    ]);

    $this->pickUp = ProductVariant::factory()->for($this->handling)->create(['name' => 'Pick up at our office', 'price_delta_cents' => 0, 'sort_order' => 0]);
    $this->ship = ProductVariant::factory()->for($this->handling)->create(['name' => 'Package and ship', 'price_delta_cents' => 7500, 'sort_order' => 1]);
});

function wizardAtAddons(Product $package)
{
    return Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $package->id)
        ->call('goToContainers')
        ->call('goToAddons');
}

test('choosing an option replaces the previously chosen one', function () {
    wizardAtAddons($this->package)
        ->assertSee('Package and ship')
        ->assertSee('$75.00')
        ->call('selectExtraOption', $this->handling->id, $this->pickUp->id)
        ->call('selectExtraOption', $this->handling->id, $this->ship->id);

    $cart = new Cart($this->store);
    $handlingLines = $cart->allLines()->where('product_id', $this->handling->id);

    expect($handlingLines)->toHaveCount(1)
        ->and($handlingLines->first()['variant_name'])->toBe('Package and ship')
        ->and($cart->subtotalCents())->toBe(100000 + 7500);
});

test('a required option is not pre-selected and must be chosen to continue', function () {
    $component = wizardAtAddons($this->package);

    expect((new Cart($this->store))->allLines()->where('product_id', $this->handling->id))->toBeEmpty();

    $component->call('goToKeepsakes')
        ->assertHasErrors(['options', "options.{$this->handling->id}"])
        ->assertSee('Please choose an option for Handling of cremated remains.')
        ->assertSet('step', 'addons');

    $component->call('selectExtraOption', $this->handling->id, $this->pickUp->id)
        ->call('goToKeepsakes')
        ->assertSet('step', 'keepsakes');
});

test('a required option cannot be cleared', function () {
    wizardAtAddons($this->package)
        ->call('selectExtraOption', $this->handling->id, $this->ship->id)
        ->call('selectExtraOption', $this->handling->id, null);

    expect((new Cart($this->store))->selectedVariantId($this->handling->id))->toBe($this->ship->id);
});

test('an optional option can be cleared', function () {
    $this->handling->update(['is_required' => false]);

    wizardAtAddons($this->package)
        ->call('selectExtraOption', $this->handling->id, $this->ship->id)
        ->call('selectExtraOption', $this->handling->id, null)
        ->call('goToKeepsakes')
        ->assertSet('step', 'keepsakes');

    expect((new Cart($this->store))->selectedVariantId($this->handling->id))->toBeNull();
});

test('an optional item must be answered, with an option or No thanks, to continue', function () {
    $this->handling->update(['is_required' => false]);

    $component = wizardAtAddons($this->package)
        ->call('goToKeepsakes')
        ->assertHasErrors("options.{$this->handling->id}")
        ->assertSee('Please choose an option for Handling of cremated remains, or No thanks.')
        ->assertSet('step', 'addons');

    $component->call('selectExtraOption', $this->handling->id, null)
        ->assertHasNoErrors()
        ->call('goToKeepsakes')
        ->assertSet('step', 'keepsakes');
});

test('the store\'s default option is pre-selected', function () {
    $this->ship->update(['is_default' => true]);

    wizardAtAddons($this->package)
        ->call('goToKeepsakes')
        ->assertSet('step', 'keepsakes');

    expect((new Cart($this->store))->selectedVariantId($this->handling->id))->toBe($this->ship->id);
});

test('No thanks is not overridden by the default option on a return visit', function () {
    $this->handling->update(['is_required' => false]);
    $this->ship->update(['is_default' => true]);

    wizardAtAddons($this->package)
        ->call('selectExtraOption', $this->handling->id, null)
        ->call('backTo', 'containers')
        ->call('goToAddons');

    expect((new Cart($this->store))->selectedVariantId($this->handling->id))->toBeNull();
});

test('creating an order fails when a required option has not been chosen', function () {
    $cart = new Cart($this->store);
    $cart->selectSlot($this->package);

    app(CheckoutService::class)->createOrder($this->store, $cart, [
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'relationship_to_deceased' => 'Adult child',
        'deceased_first_name' => 'Pat',
        'deceased_last_name' => 'Rivera',
    ]);
})->throws(RuntimeException::class);

test('choose-one items show after the add-ons and services', function () {
    Product::factory()->for($this->store)->category(ProductCategory::Addon)->create(['name' => 'Memorial folders', 'sort_order' => 99]);

    wizardAtAddons($this->package)->assertSeeInOrder(['Memorial folders', 'Handling of cremated remains']);
});

test('choose-one items without any options are not shown', function () {
    Product::factory()->for($this->store)->category(ProductCategory::Choice)->create(['name' => 'Empty choice']);

    wizardAtAddons($this->package)->assertDontSee('Empty choice');
});

test('options with a description offer a show detail link', function () {
    $this->ship->update(['description' => 'Sent by USPS Priority Mail Express.']);

    wizardAtAddons($this->package)
        ->assertSee('Show detail')
        ->assertSee('Sent by USPS Priority Mail Express.');
});

test('options without a description have no show detail link', function () {
    wizardAtAddons($this->package)->assertDontSee('Show detail');
});
