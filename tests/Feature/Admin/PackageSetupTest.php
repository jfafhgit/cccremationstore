<?php

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\ProductVariant;
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
        ->assertSet('formIncludedProducts', [$this->certificates->id => ['included' => true, 'quantity' => 3, 'variant_id' => null]]);
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

test('a keepsake allowance saves its amount, which is required, and other categories clear it', function () {
    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('newProduct', ProductCategory::KeepsakeAllowance->value)
        ->set('formName', 'Legacy Touch Allowance')
        ->call('saveProduct')
        ->assertHasErrors('formKeepsakeAllowance')
        ->set('formKeepsakeAllowance', '300.00')
        ->call('saveProduct')
        ->assertHasNoErrors();

    $allowance = $this->store->products()->firstWhere('name', 'Legacy Touch Allowance');

    expect($allowance->category)->toBe(ProductCategory::KeepsakeAllowance)
        ->and($allowance->keepsake_allowance_cents)->toBe(30000);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $allowance->id)
        ->assertSet('formKeepsakeAllowance', '300.00')
        ->set('formCategory', ProductCategory::Addon->value)
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($allowance->fresh()->keepsake_allowance_cents)->toBeNull();
});

test('a package can include a keepsake allowance', function () {
    $allowance = Product::factory()->for($this->store)->category(ProductCategory::KeepsakeAllowance)->create(['keepsake_allowance_cents' => 30000]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->package->id)
        ->assertSee($allowance->name.' (Keepsake Allowance)')
        ->set('formIncludedProducts', [$allowance->id => ['included' => true, 'quantity' => '1']])
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($this->package->fresh()->includedProducts->pluck('id')->all())->toBe([$allowance->id]);
});

test('only taxed products are tagged on the products page', function () {
    $this->store->products()->update(['is_taxable' => false]);
    $this->keepsake->update(['is_taxable' => true]);

    $html = Livewire::test('pages::admin.stores.products', ['store' => $this->store])->html();

    expect(substr_count($html, 'Taxed'))->toBe(1);
});

test('a per-unit item can be included with 0 units to cover only its base fee', function () {
    $this->certificates->update(['price_cents' => 25000, 'per_unit_price_cents' => 1500]);

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->package->id)
        ->set('formIncludedProducts', [$this->certificates->id => ['included' => true, 'quantity' => '0']])
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($this->package->fresh()->includedProducts->first()->pivot->included_quantity)->toBe(0);
});

test('an add-on saves its section heading, and categories off the add-ons step clear it', function () {
    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->certificates->id)
        ->set('formSectionHeading', '  Documents ')
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($this->certificates->fresh()->section_heading)->toBe('Documents');

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->certificates->id)
        ->assertSet('formSectionHeading', 'Documents')
        ->set('formCategory', ProductCategory::Keepsake->value)
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($this->certificates->fresh()->section_heading)->toBeNull();
});

test('a package can include one option of a choose-one item, which is required', function () {
    $care = Product::factory()->for($this->store)->category(ProductCategory::Choice)->create(['name' => 'Care of Deceased']);
    $refrigeration = ProductVariant::factory()->for($care)->create(['name' => 'Refrigeration']);
    $otherStoresOption = ProductVariant::factory()->create();

    Livewire::test('pages::admin.stores.products', ['store' => $this->store])
        ->call('editProduct', $this->package->id)
        ->assertSee('Care of Deceased (Choose-One Item)')
        ->set('formIncludedProducts', [$care->id => ['included' => true, 'variant_id' => '']])
        ->call('saveProduct')
        ->assertHasErrors('formIncludedProducts.'.$care->id.'.variant_id')
        ->set('formIncludedProducts', [$care->id => ['included' => true, 'variant_id' => (string) $otherStoresOption->id]])
        ->call('saveProduct')
        ->assertHasErrors('formIncludedProducts.'.$care->id.'.variant_id')
        ->set('formIncludedProducts', [$care->id => ['included' => true, 'variant_id' => (string) $refrigeration->id]])
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($this->package->fresh()->includedProducts->first()->pivot->included_variant_id)->toBe($refrigeration->id);
});
