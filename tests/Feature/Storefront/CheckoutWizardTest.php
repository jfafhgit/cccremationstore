<?php

use App\Enums\OrderTiming;
use App\Enums\ProductCategory;
use App\Enums\StorePath;
use App\Enums\StoreSaleType;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Services\Cart;
use App\Services\CheckoutService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->store = Store::factory()->stripeConnected()->create();
    actingAsTenant($this->store);

    $this->package = Product::factory()->for($this->store)->category(ProductCategory::Package)->create([
        'price_cents' => 100000,
    ]);

    $this->keepsake = Product::factory()->for($this->store)->category(ProductCategory::Keepsake)->create([
        'price_cents' => 5000,
    ]);
});

function fillMinimalDetails($component): void
{
    $component
        ->set('deceasedFirstName', 'Pat')
        ->set('deceasedLastName', 'Rivera')
        ->set('relationshipToDeceased', 'Adult child')
        ->set('purchaserFirstName', 'Sam')
        ->set('purchaserLastName', 'Rivera')
        ->set('purchaserEmail', 'sam@example.com')
        ->set('purchaserPhone', '555-0100');
}

function preNeedDetailsStep(Product $package): Testable
{
    return Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectPackage', $package->id)
        ->call('goToContainers')
        ->call('goToAddons')
        ->call('goToKeepsakes')
        ->call('goToDetails');
}

test('the wizard walks through every step in order', function () {
    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    expect($component->get('step'))->toBe('timing');

    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $this->package->id);

    // The timing step's "Continue" must land on containers, not skip
    // straight to details — this was a real bug caught during manual
    // verification (the button called goToDetails() directly).
    $component->call('goToContainers');
    expect($component->get('step'))->toBe('containers');

    $component->call('goToAddons');
    expect($component->get('step'))->toBe('addons');

    $component->call('goToKeepsakes');
    expect($component->get('step'))->toBe('keepsakes');

    $component->call('goToDetails');
    expect($component->get('step'))->toBe('details');
});

test('a package must be selected before continuing past the container step', function () {
    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    $component->call('selectTiming', 'immediate');
    $component->call('goToContainers');
    $component->call('goToAddons');

    $component->assertHasErrors('package');
    expect($component->get('step'))->toBe('containers');
});

test('submitting details creates an order with the cart contents', function () {
    config(['services.stripe.secret' => null]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $this->package->id);
    $component->call('goToContainers');
    $component->call('goToAddons');
    $component->call('goToKeepsakes');
    $component->call('setKeepsakeQty', $this->keepsake->id, null, 2);
    $component->call('goToDetails');
    fillMinimalDetails($component);
    $component->call('submitDetails');

    $order = Order::where('store_id', $this->store->id)->first();

    expect($order)->not->toBeNull()
        ->and($order->status->value)->toBe('pending_payment')
        ->and($order->purchaser_email)->toBe('sam@example.com')
        ->and($order->deceased_first_name)->toBe('Pat')
        ->and($order->subtotal_cents)->toBe(100000 + 2 * 5000)
        ->and($order->items()->count())->toBe(2);
});

test('a failed payment attempt shows a friendly error and keeps the order for retry', function () {
    // No platform Stripe key configured — createPaymentIntent() must fail
    // gracefully rather than crashing with an uncaught SDK exception, and
    // the wizard should stay put so the family can try again.
    config(['services.stripe.secret' => null]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $this->package->id);
    $component->call('goToContainers');
    $component->call('goToAddons');
    $component->call('goToKeepsakes');
    $component->call('goToDetails');
    fillMinimalDetails($component);
    $component->call('submitDetails');

    expect($component->get('paymentError'))->not->toBeNull()
        ->and($component->get('step'))->toBe('details');

    expect(Order::count())->toBe(1);

    // Retrying must not create a second order for the same attempt.
    $component->call('submitDetails');
    expect(Order::count())->toBe(1);
});

test('a store with no Stripe account shows a friendly message instead of attempting payment', function () {
    $unconnectedStore = Store::factory()->create(); // no stripe_account_id
    actingAsTenant($unconnectedStore);
    $package = Product::factory()->for($unconnectedStore)->category(ProductCategory::Package)->create();

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $package->id);
    $component->call('goToContainers');
    $component->call('goToAddons');
    $component->call('goToKeepsakes');
    $component->call('goToDetails');
    fillMinimalDetails($component);
    $component->call('submitDetails');

    expect($component->get('paymentError'))->not->toBeNull();
    expect(Order::count())->toBe(0); // never even attempted to create an order
});

test('keepsake quantities in the cart survive a fresh mount of the component', function () {
    $first = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $first->call('setKeepsakeQty', $this->keepsake->id, null, 3);

    $second = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    expect($second->get('keepsakeQty'))->toBe(["{$this->keepsake->id}-0" => 3]);
});

test('an a la carte store applies its base package automatically', function () {
    $this->store->update(['checkout_path' => StorePath::ALaCarte]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    $component->call('selectTiming', 'immediate');

    expect($component->get('packageId'))->toBe($this->package->id);

    $component->call('goToContainers')->call('goToAddons')->call('goToKeepsakes')->call('goToDetails');

    $component->assertHasNoErrors();
    expect($component->get('step'))->toBe('details');
});

test('an a la carte store cannot switch to another package', function () {
    $this->store->update(['checkout_path' => StorePath::ALaCarte]);
    $other = Product::factory()->for($this->store)->category(ProductCategory::Package)->create();

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    $component->call('selectTiming', 'immediate')->call('selectPackage', $other->id);

    expect($component->get('packageId'))->toBe($this->package->id);
});

test('a packages store lets the customer choose any package', function () {
    $other = Product::factory()->for($this->store)->category(ProductCategory::Package)->create();

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);

    $component->call('selectTiming', 'immediate')->call('selectPackage', $other->id);

    expect($component->get('packageId'))->toBe($other->id);
});

test('a required container must be selected before continuing past the container step', function () {
    $this->store->update(['requires_container' => true]);
    Product::factory()->for($this->store)->category(ProductCategory::Container)->create();

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $this->package->id);
    $component->call('goToContainers');
    $component->call('goToAddons');

    $component->assertHasErrors('container');
    expect($component->get('step'))->toBe('containers');
});

test('selecting a required container allows the wizard to proceed', function () {
    $this->store->update(['requires_container' => true]);
    $container = Product::factory()->for($this->store)->category(ProductCategory::Container)->create();

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $this->package->id);
    $component->call('goToContainers');
    $component->call('selectContainer', $container->id);
    $component->call('goToAddons');

    $component->assertHasNoErrors();
    expect($component->get('step'))->toBe('addons');
});

test('a required container that the store does not sell does not block the wizard', function () {
    $this->store->update(['requires_container' => true]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $this->package->id);
    $component->call('goToContainers');
    $component->call('goToAddons');

    $component->assertHasNoErrors();
    expect($component->get('step'))->toBe('addons');
});

test('removing the package from the cart drawer clears the cart and returns to the start', function () {
    $container = Product::factory()->for($this->store)->category(ProductCategory::Container)->create();

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->package->id)
        ->call('goToContainers')
        ->call('selectContainer', $container->id)
        ->call('setKeepsakeQty', $this->keepsake->id, null, 2);

    Livewire::test('storefront.cart-drawer', ['store' => $this->store])
        ->call('removeLine', 'package')
        ->assertRedirect(route('storefront.start', ['store' => $this->store->slug]));

    $cart = new Cart($this->store);
    expect($cart->isEmpty())->toBeTrue()
        ->and($cart->timing())->toBeNull();
});

test('the addons step and the keepsakes step show only their own products', function () {
    $addon = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create(['name' => 'Certified copies']);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page']);
    $component->call('selectTiming', 'immediate');
    $component->call('selectPackage', $this->package->id);
    $component->call('goToContainers');
    $component->call('goToAddons');

    $component->assertSee('Certified copies')->assertDontSee($this->keepsake->name);

    $component->call('goToKeepsakes');

    $component->assertSee($this->keepsake->name)->assertDontSee('Certified copies');
});

test('included items are listed on the package card', function () {
    $this->package->update(['included_items' => ['Basic container', 'Cremation permit']]);

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->assertSeeInOrder(['Basic container', 'Cremation permit']);
});

test('the storefront header shows the store logo when one is uploaded', function () {
    $this->store->update(['brand_logo_path' => 'logos/example.png']);

    $this->get(route('storefront.start'))
        ->assertOk()
        ->assertSee($this->store->brandLogoUrl(), escape: false)
        ->assertSee('alt="'.e($this->store->name).'"', escape: false);
});

test('the chosen package is marked selected in a packages store', function () {
    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->assertDontSee('Selected')
        ->call('selectPackage', $this->package->id)
        ->assertSee('Selected');
});

test('an a la carte store does not mark its base package as selected', function () {
    $this->store->update(['checkout_path' => StorePath::ALaCarte]);

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->assertSet('packageId', $this->package->id)
        ->assertDontSee('Selected');
});

test('clicking an add-on card selects it and clicking again deselects it', function () {
    $addon = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create(['price_cents' => 2500]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->package->id);

    $component->call('toggleExtra', $addon->id);
    expect((new Cart($this->store))->allLines()->get($addon->id.'-0')['quantity'])->toBe(1);

    $component->call('toggleExtra', $addon->id);
    expect((new Cart($this->store))->allLines()->has($addon->id.'-0'))->toBeFalse();
});

test('required add-ons cannot be deselected', function () {
    $addon = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create(['is_required' => true]);

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->package->id)
        ->call('goToContainers')
        ->call('goToAddons')
        ->call('toggleExtra', $addon->id);

    expect((new Cart($this->store))->allLines()->has($addon->id.'-0'))->toBeTrue();
});

test('only add-ons that allow multiple quantity show a quantity selector', function () {
    $single = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create(['name' => 'Viewing', 'allow_multiple_quantity' => false]);
    $multiple = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create(['name' => 'Flag case', 'allow_multiple_quantity' => true]);

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->package->id)
        ->call('toggleExtra', $single->id)
        ->call('toggleExtra', $multiple->id)
        ->call('goToContainers')
        ->call('goToAddons')
        ->assertDontSee('Increase quantity of Viewing')
        ->assertSee('Increase quantity of Flag case');
});

test('an add-on with a quantity selector shows its live total', function () {
    $addon = Product::factory()->for($this->store)->category(ProductCategory::Addon)->create([
        'price_cents' => 2500,
        'allow_multiple_quantity' => true,
    ]);

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->package->id)
        ->call('goToContainers')
        ->call('goToAddons')
        ->call('toggleExtra', $addon->id)
        ->assertSee('Total: $25.00')
        ->call('setKeepsakeQty', $addon->id, null, 3)
        ->assertSee('Total: $75.00');
});

test('the embedded storefront has its own cart button since it has no header', function () {
    $this->get(route('storefront.embed', ['store' => $this->store->slug]))
        ->assertOk()
        ->assertSee(__('Open cart'))
        ->assertSeeLivewire('storefront.cart-drawer');
});

test('starting over from the embedded cart stays inside the embed', function () {
    (new Cart($this->store))->selectSlot($this->package);

    Livewire::test('storefront.cart-drawer', ['store' => $this->store, 'embedded' => true])
        ->call('startOver')
        ->assertRedirect(route('storefront.embed', ['store' => $this->store->slug]));
});

test('the embed session check reports the token of the session the browser sent', function () {
    $this->withSession(['_token' => 'kept-session-token'])
        ->getJson(route('storefront.embed.session-check', ['store' => $this->store->slug]))
        ->assertOk()
        ->assertExactJson(['token' => 'kept-session-token'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('the embedded storefront can fall back to its own window when cookies are blocked', function () {
    $this->get(route('storefront.embed', ['store' => $this->store->slug]))
        ->assertOk()
        ->assertSeeHtml('id="tm-cookie-fallback" hidden')
        ->assertSee('Open our store in a new window')
        ->assertSeeHtml('href="'.route('storefront.start', ['store' => $this->store->slug]).'" target="_blank"')
        ->assertSee('\/embed\/session-check', escape: false);
});

test('the storefront header links back to the funeral home website, but the embed does not', function () {
    $this->store->update(['website_url' => 'https://riverside.example']);

    $this->get(route('storefront.start', ['store' => $this->store->slug]))
        ->assertOk()
        ->assertSee("Back to {$this->store->name} website")
        ->assertSeeHtml('href="https://riverside.example"');

    $this->get(route('storefront.embed', ['store' => $this->store->slug]))
        ->assertOk()
        ->assertDontSee("Back to {$this->store->name} website");
});

describe('a pre-need store', function () {
    beforeEach(function () {
        config(['services.stripe.secret' => null]);
        $this->store->update(['sale_type' => StoreSaleType::PreNeed]);
    });

    test('skips the timing question and orders as pre-need', function () {
        Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
            ->assertDontSee(OrderTiming::Immediate->label())
            ->assertDontSee(OrderTiming::Imminent->label());

        $component = preNeedDetailsStep($this->package)->set('arrangementFor', 'someone_else');
        fillMinimalDetails($component);
        $component->call('submitDetails');

        expect(Order::where('store_id', $this->store->id)->first())
            ->timing->toBe(OrderTiming::PreNeed)
            ->deceased_first_name->toBe('Pat')
            ->relationship_to_deceased->toBe('Adult child');
    });

    test('asks who the arrangement is for', function () {
        $component = preNeedDetailsStep($this->package);
        fillMinimalDetails($component);

        $component->call('submitDetails')->assertHasErrors(['arrangementFor' => 'required']);
    });

    test('someone planning for themselves only gives their own details', function () {
        preNeedDetailsStep($this->package)
            ->set('arrangementFor', 'self')
            ->set('purchaserFirstName', 'Sam')
            ->set('purchaserLastName', 'Rivera')
            ->set('purchaserEmail', 'sam@example.com')
            ->set('purchaserPhone', '555-0100')
            ->call('submitDetails')
            ->assertHasNoErrors();

        expect(Order::where('store_id', $this->store->id)->first())
            ->deceased_first_name->toBe('Sam')
            ->deceased_last_name->toBe('Rivera')
            ->relationship_to_deceased->toBe('Self');
    });

    test('someone planning for another person must say who and how they are related', function () {
        preNeedDetailsStep($this->package)
            ->set('arrangementFor', 'someone_else')
            ->set('purchaserFirstName', 'Sam')
            ->set('purchaserLastName', 'Rivera')
            ->set('purchaserEmail', 'sam@example.com')
            ->set('purchaserPhone', '555-0100')
            ->call('submitDetails')
            ->assertHasErrors(['deceasedFirstName', 'deceasedLastName', 'relationshipToDeceased']);
    });
});

test('an at-need store cannot be ordered from as pre-need', function () {
    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'pre_need')
        ->assertSet('timing', null);

    expect((new Cart($this->store))->timing())->toBeNull();
});

test('the timing answer is only recorded and offers the same products either way', function (string $timing) {
    $urn = Product::factory()->for($this->store)->category(ProductCategory::Urn)->create();

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', $timing);

    expect($component->instance()->urns()->pluck('id')->all())->toBe([$urn->id])
        ->and($component->instance()->packages()->pluck('id')->all())->toBe([$this->package->id]);
})->with(['immediate', 'imminent']);

test('the urn vault section only appears when the store offers urn vaults', function () {
    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->package->id)
        ->call('goToContainers');

    $component->assertSee('Container & Urn')->assertDontSee('Urn vault');

    Product::factory()->for($this->store)->category(ProductCategory::UrnVault)->create(['name' => 'No Burial', 'price_cents' => 0]);

    $component->call('goToContainers')
        ->assertSee('Container, Urn & Vault')
        ->assertSee('Urn vault')
        ->assertSee('No Burial');
});

test('choosing an urn vault adds it to the cart in place of any earlier choice', function () {
    $noBurial = Product::factory()->for($this->store)->category(ProductCategory::UrnVault)->create(['price_cents' => 0]);
    $vault = Product::factory()->for($this->store)->category(ProductCategory::UrnVault)->create(['price_cents' => 45000]);

    Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->package->id)
        ->call('goToContainers')
        ->call('selectUrnVault', $vault->id)
        ->call('selectUrnVault', $noBurial->id)
        ->assertSet('urnVaultId', $noBurial->id);

    $lines = (new Cart($this->store))->allLines();
    expect($lines->get('urn_vault')['product_id'])->toBe($noBurial->id)
        ->and($lines->where('category', ProductCategory::UrnVault->value))->toHaveCount(1);
});

test('a required urn vault must be selected before continuing past the container step', function () {
    $this->store->update(['requires_urn_vault' => true]);
    $vault = Product::factory()->for($this->store)->category(ProductCategory::UrnVault)->create();

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->package->id)
        ->call('goToContainers')
        ->call('goToAddons');

    $component->assertHasErrors('urn_vault')->assertSet('step', 'containers');

    $component->call('selectUrnVault', $vault->id)->call('goToAddons');

    $component->assertHasNoErrors()->assertSet('step', 'addons');
});

test('a required urn vault cannot be cleared from the cart', function () {
    $this->store->update(['requires_urn_vault' => true]);
    $vault = Product::factory()->for($this->store)->category(ProductCategory::UrnVault)->create();
    $cart = new Cart($this->store);
    $cart->selectSlot($vault);

    $cart->removeAny('urn_vault');

    expect($cart->hasUrnVault())->toBeTrue();
});

test('a cart saved before urn vaults existed still loads', function () {
    session()->put("cart.{$this->store->id}", [
        'timing' => 'immediate',
        'location_id' => null,
        'package' => null,
        'container' => null,
        'urn' => null,
        'lines' => [],
        'declined_options' => [],
        'pending_order_id' => null,
    ]);

    $cart = new Cart($this->store);

    expect($cart->hasUrnVault())->toBeFalse()
        ->and($cart->isEmpty())->toBeTrue();
});

test('an order cannot be created without a required urn vault', function () {
    $this->store->update(['requires_urn_vault' => true]);
    Product::factory()->for($this->store)->category(ProductCategory::UrnVault)->create();
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
})->throws(RuntimeException::class, 'An urn vault selection is required');

test('a $0.00 urn vault such as No Burial is pre-selected, but a choice already made stands', function () {
    $vault = Product::factory()->for($this->store)->category(ProductCategory::UrnVault)->create(['price_cents' => 45000, 'sort_order' => 1]);
    $noBurial = Product::factory()->for($this->store)->category(ProductCategory::UrnVault)->create(['price_cents' => 0, 'sort_order' => 2]);

    $component = Livewire::test('storefront.checkout-wizard', ['context' => 'page'])
        ->call('selectTiming', 'immediate')
        ->call('selectPackage', $this->package->id)
        ->call('goToContainers');

    $component->assertSet('urnVaultId', $noBurial->id);

    $component->call('selectUrnVault', $vault->id)->call('backTo', 'timing')->call('goToContainers');

    $component->assertSet('urnVaultId', $vault->id);
});
