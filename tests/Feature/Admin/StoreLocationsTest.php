<?php

use App\Enums\ProductCategory;
use App\Enums\UsState;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreLocation;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->store = Store::factory()->create();
});

test('location-based pricing can be turned on', function () {
    Livewire::test('pages::admin.stores.locations', ['store' => $this->store])
        ->set('locationPricingEnabled', true);

    expect($this->store->fresh()->location_pricing_enabled)->toBeTrue();
});

test('cities are added under a state', function () {
    Livewire::test('pages::admin.stores.locations', ['store' => $this->store])
        ->set('newState', 'IL')
        ->set('newCity', '  Springfield ')
        ->call('addLocation')
        ->assertHasNoErrors()
        ->assertSet('newState', 'IL')
        ->assertSet('newCity', '');

    expect($this->store->locations()->get()->map->only(['state', 'city'])->all())
        ->toBe([['state' => UsState::IL, 'city' => 'Springfield']]);
});

test('a city can only be listed once per state', function () {
    StoreLocation::factory()->for($this->store)->create(['state' => UsState::IL, 'city' => 'Springfield']);

    $component = Livewire::test('pages::admin.stores.locations', ['store' => $this->store])
        ->set('newState', 'IL')
        ->set('newCity', 'Springfield')
        ->call('addLocation')
        ->assertHasErrors('newCity');

    $component->set('newState', 'MO')->call('addLocation')->assertHasNoErrors();

    expect($this->store->locations()->count())->toBe(2);
});

test('a city needs a valid state and a name', function (string $state, string $city, string $field) {
    Livewire::test('pages::admin.stores.locations', ['store' => $this->store])
        ->set('newState', $state)
        ->set('newCity', $city)
        ->call('addLocation')
        ->assertHasErrors($field);
})->with([
    'no state' => ['', 'Springfield', 'newState'],
    'unknown state' => ['ZZ', 'Springfield', 'newState'],
    'no city' => ['IL', '   ', 'newCity'],
]);

test('removing a city removes the package prices set for it', function () {
    $location = StoreLocation::factory()->for($this->store)->create();
    $package = Product::factory()->for($this->store)->create();
    $package->locationPrices()->attach($location->id, ['price_cents' => 120000]);

    Livewire::test('pages::admin.stores.locations', ['store' => $this->store])
        ->call('removeLocation', $location->id);

    expect($this->store->locations()->exists())->toBeFalse()
        ->and($package->locationPrices()->exists())->toBeFalse();
});

test('another store\'s city cannot be removed', function () {
    $otherLocation = StoreLocation::factory()->create();

    Livewire::test('pages::admin.stores.locations', ['store' => $this->store])
        ->call('removeLocation', $otherLocation->id);

    expect($otherLocation->fresh())->not->toBeNull();
});

test('a package saves a price per city, leaving blank cities unoffered', function () {
    $this->store->update(['location_pricing_enabled' => true]);
    $springfield = StoreLocation::factory()->for($this->store)->create(['city' => 'Springfield']);
    $peoria = StoreLocation::factory()->for($this->store)->create(['city' => 'Peoria']);
    $otherStoresCity = StoreLocation::factory()->create();
    $package = Product::factory()->for($this->store)->create(['taxable_amount_cents' => 0]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $package->id)
        ->set('formLocationPrices', [
            $springfield->id => '1200.00',
            $peoria->id => '',
            $otherStoresCity->id => '999.00',
        ])
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($package->locationPrices()->get()->mapWithKeys(fn (StoreLocation $location) => [$location->id => $location->pivot->price_cents])->all())
        ->toBe([$springfield->id => 120000]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $package->id)
        ->assertSet('formLocationPrices', [$springfield->id => '1200.00']);
});

test('a city price cannot be negative', function () {
    $this->store->update(['location_pricing_enabled' => true]);
    $location = StoreLocation::factory()->for($this->store)->create();
    $package = Product::factory()->for($this->store)->create(['taxable_amount_cents' => 0]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $package->id)
        ->set('formLocationPrices', [$location->id => '-5'])
        ->call('saveProduct')
        ->assertHasErrors('formLocationPrices.'.$location->id);
});

test('saving a package while location pricing is off keeps its city prices', function () {
    $location = StoreLocation::factory()->for($this->store)->create();
    $package = Product::factory()->for($this->store)->create(['taxable_amount_cents' => 0]);
    $package->locationPrices()->attach($location->id, ['price_cents' => 120000]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $package->id)
        ->set('formLocationPrices', [])
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($package->locationPrices()->count())->toBe(1);
});

test('a package changed to another category loses its city prices', function () {
    $this->store->update(['location_pricing_enabled' => true]);
    $location = StoreLocation::factory()->for($this->store)->create();
    $package = Product::factory()->for($this->store)->create(['taxable_amount_cents' => 0]);
    $package->locationPrices()->attach($location->id, ['price_cents' => 120000]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $package->id)
        ->set('formCategory', ProductCategory::Addon->value)
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($package->locationPrices()->exists())->toBeFalse();
});
