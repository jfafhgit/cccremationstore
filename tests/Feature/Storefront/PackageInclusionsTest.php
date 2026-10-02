<?php

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Store;
use App\Services\Cart;
use App\Services\CheckoutService;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->stripeConnected()->create(['tax_rate_bps' => 1000]);
    actingAsTenant($this->store);

    $this->basic = Product::factory()->for($this->store)->create(['name' => 'Basic', 'price_cents' => 100000]);
    $this->premium = Product::factory()->for($this->store)->create(['name' => 'Premium', 'price_cents' => 300000]);

    $this->certificates = Product::factory()->for($this->store)->category(ProductCategory::Service)->create([
        'name' => 'Death certificates',
        'price_cents' => 25000,
        'per_unit_price_cents' => 1500,
        'per_unit_label' => 'copy',
        'is_taxable' => false,
    ]);
    $this->memorial = Product::factory()->for($this->store)->category(ProductCategory::Service)->create([
        'name' => 'Memorial service',
        'price_cents' => 80000,
        'is_taxable' => false,
    ]);

    $this->premium->includedProducts()->attach([
        $this->certificates->id => ['included_quantity' => 2],
        $this->memorial->id => ['included_quantity' => 1],
    ]);

    $this->certificatesKey = $this->certificates->id.'-0';
    $this->memorialKey = $this->memorial->id.'-0';
});

test('a package adds its included items at no charge', function () {
    $cart = new Cart($this->store);
    $cart->selectSlot($this->premium);

    $lines = $cart->allLines();

    expect($lines->get($this->certificatesKey)['quantity'])->toBe(2)
        ->and($lines->get($this->memorialKey)['quantity'])->toBe(1)
        ->and($cart->subtotalCents())->toBe(300000);
});

test('units beyond the included quantity are charged without the base fee', function () {
    $cart = new Cart($this->store);
    $cart->selectSlot($this->premium);
    $cart->updateLineQuantity($this->certificatesKey, 5);

    expect($cart->subtotalCents())->toBe(300000 + 3 * 1500);
});

test('included units cannot be removed', function () {
    $cart = new Cart($this->store);
    $cart->selectSlot($this->premium);

    $cart->updateLineQuantity($this->certificatesKey, 0);
    $cart->removeAny($this->memorialKey);

    $lines = (new Cart($this->store))->allLines();

    expect($lines->get($this->certificatesKey)['quantity'])->toBe(2)
        ->and($lines->get($this->memorialKey)['quantity'])->toBe(1);
});

test('removing an included item drops only the extras added on top', function () {
    $cart = new Cart($this->store);
    $cart->selectSlot($this->premium);
    $cart->updateLineQuantity($this->certificatesKey, 4);

    $cart->removeAny($this->certificatesKey);

    expect($cart->allLines()->get($this->certificatesKey)['quantity'])->toBe(2);
});

test('an item already in the cart is not charged again when an upgraded package includes it', function () {
    $cart = new Cart($this->store);
    $cart->selectSlot($this->basic);
    $cart->addLine($this->memorial);

    $cart->selectSlot($this->premium);

    expect($cart->allLines()->get($this->memorialKey)['quantity'])->toBe(1)
        ->and($cart->subtotalCents())->toBe(300000);
});

test('switching away from a package removes its inclusions but keeps what the customer chose', function () {
    $cart = new Cart($this->store);
    $cart->selectSlot($this->basic);
    $cart->addLine($this->certificates, null, 3);

    $cart->selectSlot($this->premium);
    expect($cart->allLines()->get($this->certificatesKey)['quantity'])->toBe(3);

    $cart->selectSlot($this->basic);

    $lines = $cart->allLines();
    expect($lines->get($this->certificatesKey)['quantity'])->toBe(3)
        ->and($lines->has($this->memorialKey))->toBeFalse()
        ->and($cart->subtotalCents())->toBe(100000 + 25000 + 3 * 1500);
});

test('extras added under a package are kept when switching away from it', function () {
    $cart = new Cart($this->store);
    $cart->selectSlot($this->premium);
    $cart->updateLineQuantity($this->certificatesKey, 4);

    $cart->selectSlot($this->basic);

    expect($cart->allLines()->get($this->certificatesKey)['quantity'])->toBe(2);
});

test('a required item the package includes is not charged', function () {
    $this->memorial->update(['is_required' => true]);

    $cart = new Cart($this->store);
    $cart->ensureRequiredLines();
    $cart->selectSlot($this->premium);
    $cart->ensureRequiredLines();

    expect($cart->allLines()->get($this->memorialKey)['quantity'])->toBe(1)
        ->and($cart->subtotalCents())->toBe(300000);
});

test('the package allowance is credited against the selected urn, which is still taxed at its full price', function () {
    $this->premium->update(['urn_allowance_cents' => 20000, 'taxable_amount_cents' => 0, 'is_taxable' => false]);
    $urn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 35000, 'is_taxable' => true]);
    $budgetUrn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 15000, 'is_taxable' => true]);

    $cart = new Cart($this->store);
    $cart->selectSlot($this->premium->fresh());
    $cart->selectSlot($urn);

    expect($cart->lineTotalCents($cart->allLines()->get('urn')))->toBe(15000)
        ->and($cart->taxCents())->toBe(3500);

    $cart->selectSlot($budgetUrn);

    expect($cart->lineTotalCents($cart->allLines()->get('urn')))->toBe(0)
        ->and($cart->taxCents())->toBe(1500);
});

test('an included taxable item is taxed at its regular price', function () {
    $this->premium->update(['taxable_amount_cents' => 0, 'is_taxable' => false]);
    $keepsake = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create(['price_cents' => 5000, 'is_taxable' => true]);
    $this->premium->includedProducts()->attach($keepsake->id, ['included_quantity' => 1]);

    $cart = new Cart($this->store);
    $cart->selectSlot($this->premium->fresh());
    $cart->updateLineQuantity($keepsake->id.'-0', 2);

    expect($cart->subtotalCents())->toBe(300000 + 5000)
        ->and($cart->taxCents())->toBe(1000);
});

test('the allowance follows the package when the package changes after the urn is chosen', function () {
    $this->premium->update(['urn_allowance_cents' => 20000]);
    $urn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 35000]);

    $cart = new Cart($this->store);
    $cart->selectSlot($this->basic);
    $cart->selectSlot($urn);
    expect($cart->lineTotalCents($cart->allLines()->get('urn')))->toBe(35000);

    $cart->selectSlot($this->premium->fresh());
    expect($cart->lineTotalCents($cart->allLines()->get('urn')))->toBe(15000);
});

test('included quantities and allowances are snapshotted on the order', function () {
    $this->premium->update(['urn_allowance_cents' => 20000]);
    $urn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 35000, 'is_taxable' => false]);

    $cart = new Cart($this->store);
    $cart->selectSlot($this->premium->fresh());
    $cart->selectSlot($urn);
    $cart->updateLineQuantity($this->certificatesKey, 3);

    $order = app(CheckoutService::class)->createOrder($this->store, $cart, [
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'relationship_to_deceased' => 'Adult child',
        'deceased_first_name' => 'Pat',
        'deceased_last_name' => 'Rivera',
    ]);

    $certificatesItem = $order->items->firstWhere('product_id', $this->certificates->id);
    $urnItem = $order->items->firstWhere('product_id', $urn->id);

    expect($certificatesItem->only(['quantity', 'included_quantity', 'total_price_cents']))
        ->toBe(['quantity' => 3, 'included_quantity' => 2, 'total_price_cents' => 1500])
        ->and($urnItem->only(['allowance_cents', 'total_price_cents']))
        ->toBe(['allowance_cents' => 20000, 'total_price_cents' => 15000])
        ->and($order->subtotal_cents)->toBe(300000 + 1500 + 15000);
});

test('the cart shows regular prices with the package discount on its own line', function () {
    $this->premium->update(['urn_allowance_cents' => 20000]);
    $urn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 35000]);

    $cart = new Cart($this->store);
    $cart->selectSlot($this->premium->fresh());
    $cart->selectSlot($urn);

    Livewire::test('storefront.cart-drawer', ['store' => $this->store])
        ->assertSeeInOrder(['$350.00', 'Package allowance', '−$200.00'])
        ->assertSeeInOrder(['$800.00', 'Included with package', '−$800.00'])
        ->assertSeeInOrder(['$280.00', '2 included with package', '−$280.00']);
});

test('the wizard pre-selects the first urn the allowance fully covers', function () {
    $this->premium->update(['urn_allowance_cents' => 20000]);
    Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 35000, 'sort_order' => 1]);
    $coveredUrn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 20000, 'sort_order' => 2]);

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->premium->id)
        ->call('goToContainers')
        ->assertSet('urnId', $coveredUrn->id);
});

test('a package can hide urns priced below its allowance', function () {
    $this->premium->update(['urn_allowance_cents' => 20000, 'hide_options_below_allowance' => true]);
    $cheapUrn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 5000]);
    $coveredUrn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 20000]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->premium->id);

    expect($component->instance()->urns()->pluck('id')->all())->toBe([$coveredUrn->id]);

    $component->call('selectUrn', $cheapUrn->id);
    expect((new Cart($this->store))->hasUrn())->toBeFalse();
});

test('every urn is shown when the allowance would hide them all', function () {
    $this->premium->update(['urn_allowance_cents' => 90000, 'hide_options_below_allowance' => true]);
    Product::factory()->for($this->store)->category(ProductCategory::Urn)->count(2)->sequence(['slug' => 'urn-a'], ['slug' => 'urn-b'])->create(['price_cents' => 5000]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->premium->id);

    expect($component->instance()->urns())->toHaveCount(2);
});

test('switching to a package that hides the chosen urn swaps in the urn it covers', function () {
    $this->premium->update(['urn_allowance_cents' => 20000, 'hide_options_below_allowance' => true]);
    $cheapUrn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 5000]);
    $coveredUrn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create(['price_cents' => 20000]);

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->basic->id)
        ->call('selectUrn', $cheapUrn->id)
        ->call('selectPackage', $this->premium->id)
        ->assertSet('urnId', $coveredUrn->id);
});

test('the addons step marks items the package includes', function () {
    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->premium->id)
        ->call('goToContainers')
        ->call('goToAddons')
        ->assertSee('2 included, then $15.00 per copy')
        ->assertSee('Included with your package');
});
