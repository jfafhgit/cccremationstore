<?php

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Store;
use App\Services\Cart;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->create();
    actingAsTenant($this->store);

    $this->single = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create(['name' => 'Viewing', 'allow_multiple_quantity' => false]);
    $this->multiple = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create(['name' => 'Flag case', 'allow_multiple_quantity' => true]);
});

test('the cart drawer shows a quantity stepper only for add-ons that allow more than one', function () {
    $cart = new Cart($this->store);
    $cart->addLine($this->single);
    $cart->addLine($this->multiple);

    Livewire::test('storefront.cart-drawer', ['store' => $this->store])
        ->assertSeeHtml("incrementLine('{$this->multiple->id}-0')")
        ->assertDontSeeHtml("incrementLine('{$this->single->id}-0')");
});

test('a single-quantity add-on cannot be increased past one', function () {
    $cart = new Cart($this->store);
    $cart->addLine($this->single, quantity: 3);
    $cart->addLine($this->multiple);

    Livewire::test('storefront.cart-drawer', ['store' => $this->store])
        ->call('incrementLine', "{$this->single->id}-0")
        ->call('incrementLine', "{$this->multiple->id}-0");

    $lines = (new Cart($this->store))->allLines();

    expect($lines->get("{$this->single->id}-0")['quantity'])->toBe(1)
        ->and($lines->get("{$this->multiple->id}-0")['quantity'])->toBe(2);
});

test('the checkout wizard cannot set a single-quantity add-on above one', function () {
    $package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create();

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $package->id)
        ->call('goToContainers')
        ->call('goToAddons')
        ->call('setKeepsakeQty', $this->single->id, null, 3)
        ->call('setKeepsakeQty', $this->multiple->id, null, 3);

    $lines = (new Cart($this->store))->allLines();

    expect($lines->get("{$this->single->id}-0")['quantity'])->toBe(1)
        ->and($lines->get("{$this->multiple->id}-0")['quantity'])->toBe(3);
});

test('the cart drawer hides the family provided container and the no burial urn vault', function () {
    $familyContainer = Product::factory()->for($this->store)->category(ProductCategory::Container)->create(['name' => 'Family Provided Cremation Container', 'price_cents' => 0, 'is_family_provided' => true]);
    $noBurial = Product::factory()->for($this->store)->category(ProductCategory::UrnVault)->create(['name' => 'No Burial', 'price_cents' => 0]);
    $urn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['name' => 'Bronze Urn']);
    $cart = new Cart($this->store);
    $cart->selectSlot($familyContainer);
    $cart->selectSlot($noBurial);
    $cart->selectSlot($urn);

    Livewire::test('storefront.cart-drawer', ['store' => $this->store])
        ->assertSee('Bronze Urn')
        ->assertDontSee('Family Provided Cremation Container')
        ->assertDontSee('No Burial');

    expect($cart->allLines()->keys()->all())->toContain('container', 'urn_vault');
});

test('the cart button marks a non-empty cart without showing a count', function () {
    $component = Livewire::test('storefront.cart-drawer', ['store' => $this->store])
        ->assertDontSeeHtml('data-cart-indicator');

    $cart = new Cart($this->store);
    $cart->addLine($this->multiple, quantity: 3);

    $component->call('refreshCart')
        ->assertSeeHtml('data-cart-indicator');
});

test('the cart drawer still shows a paid urn vault', function () {
    $vault = Product::factory()->for($this->store)->category(ProductCategory::UrnVault)->create(['name' => 'Concrete Vault', 'price_cents' => 45000]);
    (new Cart($this->store))->selectSlot($vault);

    Livewire::test('storefront.cart-drawer', ['store' => $this->store])
        ->assertSee('Concrete Vault');
});
