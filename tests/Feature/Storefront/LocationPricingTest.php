<?php

use App\Enums\ProductCategory;
use App\Enums\UsState;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreLocation;
use App\Services\Cart;
use App\Services\CheckoutService;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->stripeConnected()->create(['location_pricing_enabled' => true, 'tax_rate_bps' => 1000]);
    actingAsTenant($this->store);

    $this->springfield = StoreLocation::factory()->for($this->store)->create(['state' => UsState::IL, 'city' => 'Springfield']);
    $this->peoria = StoreLocation::factory()->for($this->store)->create(['state' => UsState::IL, 'city' => 'Peoria']);
    $this->stLouis = StoreLocation::factory()->for($this->store)->create(['state' => UsState::MO, 'city' => 'St. Louis']);

    $this->direct = Product::factory()->for($this->store)->create(['name' => 'Direct Cremation', 'price_cents' => 100000]);
    $this->direct->locationPrices()->attach([
        $this->springfield->id => ['price_cents' => 120000],
        $this->peoria->id => ['price_cents' => 135000],
    ]);

    $this->memorial = Product::factory()->for($this->store)->create(['name' => 'Memorial Package', 'price_cents' => 300000]);
    $this->memorial->locationPrices()->attach($this->springfield->id, ['price_cents' => 320000]);
});

function wizardInCity(StoreLocation $location)
{
    return Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->set('locationState', $location->state->value)
        ->set('locationId', $location->id)
        ->call('selectTiming', 'immediate');
}

test('packages are hidden until a city is chosen', function () {
    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->assertDontSee('Direct Cremation')
        ->call('goToContainers')
        ->assertHasErrors('location')
        ->assertSet('step', 'timing');
});

test('only the packages offered in the chosen city are shown, at that city\'s price', function () {
    wizardInCity($this->peoria)
        ->assertSee('Direct Cremation')
        ->assertSee('$1,350.00')
        ->assertDontSee('Memorial Package');
});

test('the cities offered are the ones listed for the chosen state', function () {
    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->set('locationState', 'IL');

    expect($component->instance()->citiesInState()->pluck('city')->all())->toBe(['Peoria', 'Springfield'])
        ->and($component->instance()->locationStates()->all())->toBe([UsState::IL, UsState::MO]);
});

test('the selected package is charged at the chosen city\'s price', function () {
    wizardInCity($this->springfield)->call('selectPackage', $this->direct->id);

    expect((new Cart($this->store))->subtotalCents())->toBe(120000);
});

test('a package not offered in the chosen city cannot be selected', function () {
    wizardInCity($this->peoria)->call('selectPackage', $this->memorial->id);

    expect((new Cart($this->store))->hasPackage())->toBeFalse();
});

test('changing the city re-prices the selected package', function () {
    wizardInCity($this->springfield)
        ->call('selectPackage', $this->direct->id)
        ->set('locationId', $this->peoria->id);

    $cart = new Cart($this->store);
    expect($cart->subtotalCents())->toBe(135000)
        ->and($cart->location()->id)->toBe($this->peoria->id);
});

test('changing to a city where the selected package is not offered starts the cart over', function () {
    $keepsake = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create();

    $component = wizardInCity($this->springfield)
        ->call('selectPackage', $this->memorial->id)
        ->call('setKeepsakeQty', $keepsake->id, null, 1)
        ->set('locationId', $this->peoria->id)
        ->assertSet('packageId', null);

    $cart = new Cart($this->store);
    expect($cart->isEmpty())->toBeTrue()
        ->and($cart->location()->id)->toBe($this->peoria->id)
        ->and($cart->timing()?->value)->toBe('immediate');
});

test('the package taxable amount is capped at the city price', function () {
    $this->direct->update(['is_taxable' => true, 'taxable_amount_cents' => 125000]);

    $cart = new Cart($this->store);
    $cart->setLocation($this->springfield);
    $cart->selectSlot($this->direct->fresh());

    expect($cart->taxableSubtotalCents())->toBe(120000);
});

test('the order records the city it was priced for', function () {
    $cart = new Cart($this->store);
    $cart->setLocation($this->stLouis);
    $this->direct->locationPrices()->attach($this->stLouis->id, ['price_cents' => 110000]);
    $cart->selectSlot($this->direct);

    $order = app(CheckoutService::class)->createOrder($this->store, $cart, orderDetails());

    expect($order->only(['service_city', 'service_state', 'subtotal_cents']))
        ->toBe(['service_city' => 'St. Louis', 'service_state' => 'MO', 'subtotal_cents' => 110000]);
});

test('an order cannot be created without a city', function () {
    $cart = new Cart($this->store);
    $cart->selectSlot($this->direct);

    app(CheckoutService::class)->createOrder($this->store, $cart, orderDetails());
})->throws(RuntimeException::class, 'A city must be chosen');

test('a store with location pricing turned on but no cities uses regular package prices', function () {
    $this->store->locations()->delete();

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->assertSee('$3,000.00')
        ->call('selectPackage', $this->memorial->id);

    expect((new Cart($this->store))->subtotalCents())->toBe(300000);
});

/**
 * @return array<string, string>
 */
function orderDetails(): array
{
    return [
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'relationship_to_deceased' => 'Adult child',
        'deceased_first_name' => 'Pat',
        'deceased_last_name' => 'Rivera',
    ];
}
