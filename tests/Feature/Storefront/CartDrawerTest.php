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
