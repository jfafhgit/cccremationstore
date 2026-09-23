<?php

use App\Enums\ProductCategory;
use App\Enums\ProductSortMode;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);

    $this->store = Store::factory()->create();
});

test('a category defaults to custom sort order', function () {
    expect($this->store->productSortMode(ProductCategory::Urn))->toBe(ProductSortMode::Custom);
});

test('choosing a sort mode persists it on the store', function () {
    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->set('sortModeChoice.urn', 'name');

    expect($this->store->fresh()->productSortMode(ProductCategory::Urn))->toBe(ProductSortMode::Name);
});

test('name sort mode orders products alphabetically regardless of entry order', function () {
    Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['name' => 'Walnut Urn']);
    Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['name' => 'Brass Urn']);

    $component = Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->set('sortModeChoice.urn', 'name');

    $names = $component->instance()->products()->get(ProductCategory::Urn->value)->pluck('name')->all();

    expect($names)->toBe(['Brass Urn', 'Walnut Urn']);
});

test('price sort mode orders products from low to high', function () {
    Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['name' => 'Expensive Urn', 'price_cents' => 50000]);
    Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['name' => 'Cheap Urn', 'price_cents' => 5000]);

    $component = Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->set('sortModeChoice.urn', 'price');

    $names = $component->instance()->products()->get(ProductCategory::Urn->value)->pluck('name')->all();

    expect($names)->toBe(['Cheap Urn', 'Expensive Urn']);
});

test('switching to custom mode snapshots the previous order into sort_order', function () {
    $walnut = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['name' => 'Walnut Urn', 'sort_order' => 5]);
    $brass = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['name' => 'Brass Urn', 'sort_order' => 1]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->set('sortModeChoice.urn', 'name') // now ordered Brass, Walnut
        ->set('sortModeChoice.urn', 'custom');

    expect($brass->fresh()->sort_order)->toBe(0)
        ->and($walnut->fresh()->sort_order)->toBe(1);
});

test('dragging a product reorders the category and renumbers sort_order', function () {
    $first = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['sort_order' => 0]);
    $second = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['sort_order' => 1]);
    $third = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['sort_order' => 2]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('reorderProduct', ProductCategory::Urn->value, $third->id, $first->id);

    expect($third->fresh()->sort_order)->toBe(0)
        ->and($first->fresh()->sort_order)->toBe(1)
        ->and($second->fresh()->sort_order)->toBe(2);
});

test('a product cannot be reordered through another store\'s category', function () {
    $otherStore = Store::factory()->create();
    $foreignProduct = Product::factory()->for($otherStore)->category(ProductCategory::Urn)->create(['sort_order' => 0]);
    $ownProduct = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['sort_order' => 0]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('reorderProduct', ProductCategory::Urn->value, $foreignProduct->id, $ownProduct->id);

    expect($foreignProduct->fresh()->sort_order)->toBe(0);
});

test('the storefront shows products in the configured sort mode', function () {
    $package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create();
    Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['name' => 'Walnut Urn', 'price_cents' => 20000]);
    Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['name' => 'Brass Urn', 'price_cents' => 10000]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->set('sortModeChoice.urn', 'name');

    actingAsTenant($this->store->fresh());

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $package->id)
        ->call('goToContainers')
        ->assertSeeInOrder(['Brass Urn', 'Walnut Urn']);
});
