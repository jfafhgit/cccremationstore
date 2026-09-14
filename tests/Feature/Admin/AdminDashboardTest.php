<?php

use App\Enums\ProductCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

test('guests cannot reach the admin area', function () {
    $this->get('/admin/stores')->assertRedirect('/login');
});

test('an authenticated admin can see the stores list', function () {
    Store::factory()->count(2)->create();

    $this->get('/admin/stores')->assertOk();
});

test('an admin can create a new store', function () {
    // Setting the "name" property triggers updatedName() automatically,
    // which derives the slug — calling that hook directly isn't allowed.
    $component = Livewire::test('pages::admin.stores.index');
    $component->set('name', 'Riverside Cremation Care');
    $component->call('createStore');

    $component->assertHasNoErrors();

    $store = Store::where('name', 'Riverside Cremation Care')->first();
    expect($store)->not->toBeNull()
        ->and($store->status->value)->toBe('draft')
        ->and($store->slug)->toBe('riverside-cremation-care');
});

test('an admin can edit a store and add a staff login', function () {
    $store = Store::factory()->create();

    $component = Livewire::test('pages::admin.stores.show', ['store' => $store->id]);
    $component->set('contactPhone', '555-000-1111');
    $component->call('save');
    $component->assertHasNoErrors();

    expect($store->fresh()->contact_phone)->toBe('555-000-1111');

    $component->set('staffName', 'Jordan Lee');
    $component->set('staffEmail', 'jordan@example.com');
    $component->call('createStaffUser');
    $component->assertHasNoErrors();

    expect($store->staff()->where('email', 'jordan@example.com')->exists())->toBeTrue();
});

test('an admin can create, edit, and remove products for a store', function () {
    $store = Store::factory()->create();

    $component = Livewire::test('pages::admin.stores.products', ['store' => $store->id]);
    $component->call('newProduct', ProductCategory::Urn->value);
    $component->set('formName', 'Marble Urn');
    $component->set('formPrice', '150.00');
    $component->call('saveProduct');
    $component->assertHasNoErrors();

    $product = $store->products()->where('name', 'Marble Urn')->first();
    expect($product)->not->toBeNull()
        ->and($product->price_cents)->toBe(15000)
        ->and($product->category)->toBe(ProductCategory::Urn);

    $component->call('editProduct', $product->id);
    $component->set('newVariantName', 'Large');
    $component->set('newVariantPrice', '25.00');
    $component->call('addVariant');

    expect($product->fresh()->variants()->count())->toBe(1);

    $component->call('deleteProduct', $product->id);
    expect($store->products()->whereKey($product->id)->exists())->toBeFalse();
});

test('a product cannot be edited through the wrong store\'s page', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $productInStoreB = Product::factory()->for($storeB)->create();

    // findOrFail() scoped to storeA's products must reject storeB's product.
    $component = Livewire::test('pages::admin.stores.products', ['store' => $storeA->id]);
    $component->call('editProduct', $productInStoreB->id);
})->throws(ModelNotFoundException::class);

test('an order cannot be viewed via a mismatched store/order pair', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $orderInStoreB = Order::factory()->create(['store_id' => $storeB->id]);

    Livewire::test('pages::admin.stores.order-detail', ['store' => $storeA->id, 'order' => $orderInStoreB->id]);
})->throws(ModelNotFoundException::class);
