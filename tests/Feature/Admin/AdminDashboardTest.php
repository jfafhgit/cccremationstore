<?php

use App\Enums\ProductCategory;
use App\Enums\StoreUserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

test('guests cannot reach the admin area', function () {
    // beforeEach signs in an admin; drop that session to act as a guest.
    auth()->forgetGuards();

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
    $productInStoreB = Product::factory()->for($storeB)->create(['name' => 'Store B Heirloom Urn']);

    // Livewire's test harness renders ModelNotFoundException as a 404
    // response rather than rethrowing it, so assert on that response.
    Livewire::test('pages::admin.stores.products', ['store' => $storeA->id])
        ->call('editProduct', $productInStoreB->id)
        ->assertNotFound()
        ->assertDontSee('Store B Heirloom Urn');
});

test('a tampered product id cannot save over another store\'s product', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $productInStoreB = Product::factory()->for($storeB)->create(['name' => 'Original', 'price_cents' => 5000]);

    Livewire::test('pages::admin.stores.products', ['store' => $storeA->id])
        ->call('newProduct', ProductCategory::Urn->value)
        ->set('formName', 'Overwritten')
        ->set('formPrice', '1.00')
        ->set('editingProductId', $productInStoreB->id)
        ->call('saveProduct')
        ->assertNotFound();

    expect($productInStoreB->fresh())
        ->name->toBe('Original')
        ->price_cents->toBe(5000);
});

test('an order cannot be viewed via a mismatched store/order pair', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $orderInStoreB = Order::factory()->create([
        'store_id' => $storeB->id,
        'deceased_first_name' => 'Rosalind',
        'purchaser_email' => 'store-b-family@example.com',
    ]);

    Livewire::test('pages::admin.stores.order-detail', ['store' => $storeA->id, 'order' => $orderInStoreB->id])
        ->assertNotFound()
        ->assertDontSee('Rosalind')
        ->assertDontSee('store-b-family@example.com');
});

test('an admin can save a package with a taxable amount', function () {
    $store = Store::factory()->create();

    $component = Livewire::test('pages::admin.stores.products', ['store' => $store])
        ->set('formName', 'Simple Package')
        ->set('formCategory', ProductCategory::Package->value)
        ->set('formPrice', '2000.00')
        ->set('formTaxableAmount', '300.00')
        ->call('saveProduct');

    $component->assertHasNoErrors();

    $product = $store->products()->firstWhere('name', 'Simple Package');
    expect($product->price_cents)->toBe(200000)
        ->and($product->taxable_amount_cents)->toBe(30000)
        ->and($product->is_taxable)->toBeTrue();
});

test('a package taxable amount cannot exceed its price', function () {
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.products', ['store' => $store])
        ->set('formName', 'Simple Package')
        ->set('formCategory', ProductCategory::Package->value)
        ->set('formPrice', '100.00')
        ->set('formTaxableAmount', '150.00')
        ->call('saveProduct')
        ->assertHasErrors('formTaxableAmount');
});

test('an admin can set a store to a la carte', function () {
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.show', ['store' => $store])
        ->set('checkoutPath', 'a_la_carte')
        ->call('save')
        ->assertHasNoErrors();

    expect($store->fresh()->isALaCarte())->toBeTrue();
});

test('an admin can enable a processing fee', function () {
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.show', ['store' => $store])
        ->set('processingFeeEnabled', true)
        ->set('processingFeePercent', '3.50')
        ->call('save')
        ->assertHasNoErrors();

    expect($store->fresh())
        ->processing_fee_enabled->toBeTrue()
        ->processing_fee_bps->toBe(350);
});

test('a package saves its included items one per line', function () {
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.products', ['store' => $store])
        ->set('formName', 'Simple Package')
        ->set('formCategory', ProductCategory::Package->value)
        ->set('formPrice', '1000.00')
        ->set('formTaxableAmount', '0')
        ->set('formIncludedItems', "Basic container\n\n  Cremation permit  \nDeath certificates")
        ->call('saveProduct')
        ->assertHasNoErrors();

    expect($store->products()->first()->included_items)
        ->toBe(['Basic container', 'Cremation permit', 'Death certificates']);
});

test('an admin can save a required per-unit service', function () {
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.products', ['store' => $store])
        ->set('formName', 'Death certificates')
        ->set('formCategory', ProductCategory::Service->value)
        ->set('formPrice', '250.00')
        ->set('formPerUnitPrice', '15.00')
        ->set('formPerUnitLabel', 'copy')
        ->set('formIsRequired', true)
        ->call('saveProduct')
        ->assertHasNoErrors();

    $product = $store->products()->first();

    expect($product->price_cents)->toBe(25000)
        ->and($product->per_unit_price_cents)->toBe(1500)
        ->and($product->per_unit_label)->toBe('copy')
        ->and($product->is_required)->toBeTrue();
});

test('packages cannot be pre-selected or priced per unit', function () {
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.products', ['store' => $store])
        ->set('formName', 'Simple Package')
        ->set('formCategory', ProductCategory::Package->value)
        ->set('formPrice', '1000.00')
        ->set('formTaxableAmount', '0')
        ->set('formPerUnitPrice', '15.00')
        ->set('formIsRequired', true)
        ->call('saveProduct')
        ->assertHasNoErrors();

    $product = $store->products()->first();

    expect($product->is_required)->toBeFalse()
        ->and($product->per_unit_price_cents)->toBeNull();
});

test('an admin can upload a general price list PDF and replace it', function () {
    Storage::fake('public');
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.show', ['store' => $store])
        ->set('generalPriceListFile', UploadedFile::fake()->create('gpl.pdf', 100, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $firstPath = $store->fresh()->general_price_list_path;
    Storage::disk('public')->assertExists($firstPath);

    Livewire::test('pages::admin.stores.show', ['store' => $store->fresh()])
        ->set('generalPriceListFile', UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'))
        ->call('save');

    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($store->fresh()->general_price_list_path);
});

test('the general price list must be a PDF', function () {
    Storage::fake('public');
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.show', ['store' => $store])
        ->set('generalPriceListFile', UploadedFile::fake()->image('gpl.png'))
        ->call('save')
        ->assertHasErrors('generalPriceListFile');

    expect($store->fresh()->general_price_list_path)->toBeNull();
});

test('an admin can remove the general price list', function () {
    Storage::fake('public');
    Storage::disk('public')->put('price-lists/old.pdf', 'pdf');
    $store = Store::factory()->create(['general_price_list_path' => 'price-lists/old.pdf']);

    Livewire::test('pages::admin.stores.show', ['store' => $store])
        ->set('removeGeneralPriceList', true)
        ->call('save');

    expect($store->fresh()->general_price_list_path)->toBeNull();
    Storage::disk('public')->assertMissing('price-lists/old.pdf');
});

test('an admin can upload a logo and replace it', function () {
    Storage::fake('public');
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.show', ['store' => $store])
        ->set('brandLogoFile', UploadedFile::fake()->image('logo.png', 600, 150))
        ->call('save')
        ->assertHasNoErrors();

    $firstPath = $store->fresh()->brand_logo_path;
    Storage::disk('public')->assertExists($firstPath);

    Livewire::test('pages::admin.stores.show', ['store' => $store->fresh()])
        ->set('brandLogoFile', UploadedFile::fake()->image('new.webp'))
        ->call('save');

    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($store->fresh()->brand_logo_path);
});

test('the logo must be a raster image', function (string $filename, string $mimeType) {
    Storage::fake('public');
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.show', ['store' => $store])
        ->set('brandLogoFile', UploadedFile::fake()->create($filename, 10, $mimeType))
        ->call('save')
        ->assertHasErrors('brandLogoFile');

    expect($store->fresh()->brand_logo_path)->toBeNull();
})->with([
    'svg' => ['logo.svg', 'image/svg+xml'],
    'pdf' => ['logo.pdf', 'application/pdf'],
]);

test('an admin can remove the logo', function () {
    Storage::fake('public');
    Storage::disk('public')->put('logos/old.png', 'png');
    $store = Store::factory()->create(['brand_logo_path' => 'logos/old.png']);

    Livewire::test('pages::admin.stores.show', ['store' => $store])
        ->set('removeBrandLogo', true)
        ->call('save');

    expect($store->fresh()->brand_logo_path)->toBeNull();
    Storage::disk('public')->assertMissing('logos/old.png');
});

test('an admin can create an owner login, who can manage billing', function () {
    $store = Store::factory()->create();

    Livewire::test('pages::admin.stores.show', ['store' => $store])
        ->set('staffName', 'Morgan Lee')
        ->set('staffEmail', 'morgan@example.com')
        ->set('staffRole', StoreUserRole::Owner->value)
        ->call('createStaffUser')
        ->assertHasNoErrors();

    expect($store->staff()->where('email', 'morgan@example.com')->first()->isOwner())->toBeTrue();
});
