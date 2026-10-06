<?php

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->store = Store::factory()->create();
    $this->package = Product::factory()->for($this->store)->create(['price_cents' => 300000, 'taxable_amount_cents' => 0]);
    $this->certificates = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create();
    $this->keepsake = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create();
});

test('a package saves its included products, allowances, and hide setting', function () {
    $otherStoresAddon = Product::factory()->category(ProductCategory::Addon)->create();
    $urn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create();

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->package->id)
        ->set('formIncludedProducts', [
            $this->certificates->id => ['included' => true, 'quantity' => '2'],
            $this->keepsake->id => ['included' => false, 'quantity' => '1'],
            $otherStoresAddon->id => ['included' => true, 'quantity' => '1'],
            $urn->id => ['included' => true, 'quantity' => '1'],
        ])
        ->set('formContainerAllowance', '')
        ->set('formUrnAllowance', '250.00')
        ->set('formHideOptionsBelowAllowance', true)
        ->call('saveProduct')
        ->assertHasNoErrors();

    $package = $this->package->fresh();

    expect($package->includedProducts->mapWithKeys(fn (Product $product) => [$product->id => $product->pivot->included_quantity])->all())
        ->toBe([$this->certificates->id => 2])
        ->and($package->container_allowance_cents)->toBeNull()
        ->and($package->urn_allowance_cents)->toBe(25000)
        ->and($package->hide_options_below_allowance)->toBeTrue();
});

test('editing a package loads its current inclusions', function () {
    $this->package->includedProducts()->attach($this->certificates->id, ['included_quantity' => 3]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->package->id)
        ->assertSet('formIncludedProducts', [$this->certificates->id => ['included' => true, 'quantity' => 3]]);
});

test('an included quantity must be at least one', function () {
    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->package->id)
        ->set('formIncludedProducts', [$this->certificates->id => ['included' => true, 'quantity' => '0']])
        ->call('saveProduct')
        ->assertHasErrors('formIncludedProducts.'.$this->certificates->id.'.quantity');
});

test('changing a package to another category clears its inclusions and allowances', function () {
    $this->package->update(['urn_allowance_cents' => 25000]);
    $this->package->includedProducts()->attach($this->certificates->id);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->package->id)
        ->set('formCategory', ProductCategory::Addon->value)
        ->call('saveProduct')
        ->assertHasNoErrors();

    $product = $this->package->fresh();

    expect($product->includedProducts)->toBeEmpty()
        ->and($product->urn_allowance_cents)->toBeNull();
});

test('an add-on saves its keepsake allowance, and other categories clear it', function () {
    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->certificates->id)
        ->set('formKeepsakeAllowance', '300.00')
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($this->certificates->fresh()->keepsake_allowance_cents)->toBe(30000);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->certificates->id)
        ->assertSet('formKeepsakeAllowance', '300.00')
        ->set('formCategory', ProductCategory::Keepsake->value)
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($this->certificates->fresh()->keepsake_allowance_cents)->toBeNull();
});
