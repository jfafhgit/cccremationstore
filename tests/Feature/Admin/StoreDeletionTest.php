<?php

use App\Enums\StoreStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    $this->store = Store::factory()->create(['name' => 'Riverside Chapel', 'status' => StoreStatus::Active]);
});

test('a store with no paid orders is deleted with its products, unpaid orders, files, and its own staff logins', function () {
    Storage::disk('public')->put('logos/riverside.png', 'logo');
    Storage::disk('public')->put('products/urn.jpg', 'urn');
    $this->store->update(['brand_logo_path' => 'logos/riverside.png']);
    $product = Product::factory()->for($this->store)->create(['image_path' => 'products/urn.jpg']);
    $unpaidOrder = Order::factory()->for($this->store)->create();
    $staffOnlyHere = StoreUser::factory()->forStore($this->store)->create();
    $otherStore = Store::factory()->create();
    $staffAtTwoStores = StoreUser::factory()->forStore($this->store)->forStore($otherStore)->create();

    Livewire::test('pages::admin.stores.show', ['store' => $this->store])
        ->set('removeConfirmation', 'Riverside Chapel')
        ->call('removeStore')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.stores.index'));

    expect(Store::withTrashed()->find($this->store->id))->toBeNull();
    $this->assertModelMissing($product);
    $this->assertModelMissing($unpaidOrder);
    $this->assertModelMissing($staffOnlyHere);
    $this->assertModelExists($staffAtTwoStores);
    expect($staffAtTwoStores->stores()->pluck('stores.id')->all())->toBe([$otherStore->id]);
    Storage::disk('public')->assertMissing(['logos/riverside.png', 'products/urn.jpg']);
});

test('a store with paid orders is archived, keeping its orders, and goes offline', function () {
    $paidOrder = Order::factory()->for($this->store)->paid()->create();
    $this->get(route('storefront.start', ['store' => $this->store->slug]))->assertOk();

    Livewire::test('pages::admin.stores.show', ['store' => $this->store])
        ->assertSee('Archive Riverside Chapel?')
        ->set('removeConfirmation', 'Riverside Chapel')
        ->call('removeStore')
        ->assertHasNoErrors();

    expect($this->store->fresh())
        ->trashed()->toBeTrue()
        ->status->toBe(StoreStatus::Suspended);
    $this->assertModelExists($paidOrder);
    expect($paidOrder->fresh()->store->is($this->store))->toBeTrue();

    $this->get(route('storefront.start', ['store' => $this->store->slug]))->assertNotFound();
});

test('an archived store is listed separately, its orders stay viewable, and it can be restored', function () {
    $paidOrder = Order::factory()->for($this->store)->paid()->create();
    $this->store->update(['status' => StoreStatus::Suspended]);
    $this->store->delete();

    $this->get(route('admin.stores.index'))->assertSeeInOrder(['Archived stores', 'Riverside Chapel']);
    $this->get(route('admin.stores.order-detail', ['store' => $this->store, 'order' => $paidOrder]))->assertOk();

    Livewire::test('pages::admin.stores.show', ['store' => $this->store])
        ->assertSee('This store was archived')
        ->call('restoreStore');

    expect($this->store->fresh())
        ->trashed()->toBeFalse()
        ->status->toBe(StoreStatus::Suspended);
});

test('a store is only removed when its name is typed exactly', function () {
    Livewire::test('pages::admin.stores.show', ['store' => $this->store])
        ->set('removeConfirmation', 'riverside')
        ->call('removeStore')
        ->assertHasErrors('removeConfirmation');

    $this->assertNotSoftDeleted($this->store);
});

test('the stores list can be searched by name, subdomain, or contact email, including archived stores', function () {
    Store::factory()->create(['name' => 'Oakwood Memorial', 'slug' => 'oakwood', 'contact_email' => 'help@oakwood.example']);
    $archived = Store::factory()->create(['name' => 'Oak Hill Cremation', 'slug' => 'oak-hill']);
    $archived->delete();

    Livewire::test('pages::admin.stores.index')
        ->set('search', 'oak')
        ->assertSee(['Oakwood Memorial', 'Oak Hill Cremation'])
        ->assertDontSee('Riverside Chapel')
        ->set('search', 'help@oakwood')
        ->assertSee('Oakwood Memorial')
        ->assertDontSee('Oak Hill Cremation')
        ->set('search', 'nothing-matches')
        ->assertSee('No stores match');
});
