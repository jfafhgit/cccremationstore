<?php

use App\Enums\CatalogCopyStatus;
use App\Enums\PlatformFeeModel;
use App\Enums\ProductCategory;
use App\Enums\StorePath;
use App\Enums\StoreSaleType;
use App\Enums\StoreStatus;
use App\Enums\UsState;
use App\Jobs\CopyStoreCatalog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreLocation;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreDuplicator;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    $this->source = Store::factory()->stripeConnected()->create([
        'name' => 'Riverside Cremation',
        'slug' => 'riverside',
        'status' => StoreStatus::Active,
        'sale_type' => StoreSaleType::PreNeed,
        'checkout_path' => StorePath::ALaCarte,
        'requires_urn' => true,
        'contact_email' => 'owner@riverside.test',
        'brand_primary_color' => '#123456',
        'tax_rate_bps' => 725,
        'processing_fee_enabled' => true,
        'platform_fee_model' => PlatformFeeModel::Subscription,
        'subscription_monthly_cents' => 9900,
        'stripe_subscription_id' => 'sub_riverside',
        'settings' => ['product_sort' => [ProductCategory::Urn->value => 'price']],
    ]);
});

function duplicateThroughAdmin(Store $source, string $name = 'Lakeside Cremation', string $slug = 'lakeside'): Store
{
    Livewire::test('pages::admin.stores.show', ['store' => $source])
        ->set('duplicateName', $name)
        ->set('duplicateSlug', $slug)
        ->call('duplicateStore')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.stores.show', Store::where('slug', $slug)->sole()));

    return Store::where('slug', $slug)->sole();
}

test('duplicating a store copies every product and its variants', function () {
    $urn = Product::factory()->for($this->source)->category(ProductCategory::Urn)->create([
        'name' => 'Walnut Urn',
        'price_cents' => 45000,
        'is_taxable' => true,
        'is_required' => true,
        'included_items' => ['Velvet bag', 'Name plate'],
        'per_unit_price_cents' => 1500,
        'per_unit_label' => 'engraving',
        'sort_order' => 3,
        'is_active' => false,
        'requires_engraving' => true,
    ]);
    ProductVariant::factory()->for($urn)->create(['name' => 'Large', 'price_delta_cents' => 5000, 'sku' => 'URN-L', 'sort_order' => 2]);
    ProductVariant::factory()->for($urn)->create(['name' => 'Small', 'price_delta_cents' => 0, 'sku' => 'URN-S', 'sort_order' => 1]);
    Product::factory()->for($this->source)->create(['name' => 'Direct Cremation']);

    $duplicate = duplicateThroughAdmin($this->source);

    expect($duplicate->products()->count())->toBe(2);

    $copiedUrn = $duplicate->products()->where('name', 'Walnut Urn')->sole();
    $ignoredColumns = ['id', 'store_id', 'image_path', 'created_at', 'updated_at'];
    expect($copiedUrn->id)->not->toBe($urn->id)
        ->and(collect($copiedUrn->getAttributes())->except($ignoredColumns)->all())
        ->toEqual(collect($urn->fresh()->getAttributes())->except($ignoredColumns)->all());

    expect($copiedUrn->variants->map->only(['name', 'price_delta_cents', 'sku', 'sort_order'])->all())->toBe([
        ['name' => 'Small', 'price_delta_cents' => 0, 'sku' => 'URN-S', 'sort_order' => 1],
        ['name' => 'Large', 'price_delta_cents' => 5000, 'sku' => 'URN-L', 'sort_order' => 2],
    ]);

    expect($this->source->products()->count())->toBe(2)
        ->and($urn->variants()->count())->toBe(2);
});

test('duplicated packages include the copies of their included products', function () {
    $package = Product::factory()->for($this->source)->create(['name' => 'Premium']);
    $certificates = Product::factory()->for($this->source)->category(ProductCategory::Addon)->create(['name' => 'Death certificates']);
    $package->includedProducts()->attach($certificates->id, ['included_quantity' => 2]);

    $duplicate = duplicateThroughAdmin($this->source);

    $copiedPackage = $duplicate->products()->where('name', 'Premium')->sole();
    $copiedCertificates = $duplicate->products()->where('name', 'Death certificates')->sole();

    expect($copiedPackage->includedProducts->mapWithKeys(fn (Product $product) => [$product->id => $product->pivot->included_quantity])->all())
        ->toBe([$copiedCertificates->id => 2]);
});

test('duplicating a store copies its cities and each package\'s city prices', function () {
    $this->source->update(['location_pricing_enabled' => true]);
    $location = StoreLocation::factory()->for($this->source)->create(['state' => UsState::IL, 'city' => 'Springfield']);
    $package = Product::factory()->for($this->source)->create(['name' => 'Direct Cremation']);
    $package->locationPrices()->attach($location->id, ['price_cents' => 120000]);

    $duplicate = duplicateThroughAdmin($this->source);

    $copiedLocation = $duplicate->locations()->sole();
    $copiedPackage = $duplicate->products()->where('name', 'Direct Cremation')->sole();

    expect($duplicate->location_pricing_enabled)->toBeTrue()
        ->and($copiedLocation->only(['state', 'city']))->toBe(['state' => UsState::IL, 'city' => 'Springfield'])
        ->and($copiedLocation->id)->not->toBe($location->id)
        ->and($copiedPackage->locationPrices->mapWithKeys(fn (StoreLocation $priced) => [$priced->id => $priced->pivot->price_cents])->all())
        ->toBe([$copiedLocation->id => 120000]);
});

test('each duplicated product gets its own copy of the image', function () {
    $imagePath = UploadedFile::fake()->image('urn.jpg')->store('products', 'public');
    $product = Product::factory()->for($this->source)->create(['image_path' => $imagePath]);

    $copiedPath = duplicateThroughAdmin($this->source)->products()->sole()->image_path;

    expect($copiedPath)->not->toBe($imagePath)->toStartWith('products/')->toEndWith('.jpg');
    Storage::disk('public')->assertExists([$imagePath, $copiedPath]);
    expect(Storage::disk('public')->get($copiedPath))->toBe(Storage::disk('public')->get($product->image_path));
});

test('a product whose image file is missing is copied without an image', function () {
    Product::factory()->for($this->source)->create(['image_path' => 'products/gone.jpg']);

    expect(duplicateThroughAdmin($this->source)->products()->sole()->image_path)->toBeNull();
});

test('the duplicate is a draft with the source\'s settings but none of its identity, accounts, staff, or orders', function () {
    StoreUser::factory()->forStore($this->source)->create();
    Order::factory()->create(['store_id' => $this->source->id]);

    $duplicate = duplicateThroughAdmin($this->source);

    expect($duplicate->name)->toBe('Lakeside Cremation')
        ->and($duplicate->status)->toBe(StoreStatus::Draft)
        ->and($duplicate->sale_type)->toBe(StoreSaleType::PreNeed)
        ->and($duplicate->checkout_path)->toBe(StorePath::ALaCarte)
        ->and($duplicate->requires_urn)->toBeTrue()
        ->and($duplicate->tax_rate_bps)->toBe(725)
        ->and($duplicate->processing_fee_enabled)->toBeTrue()
        ->and($duplicate->platform_fee_model)->toBe(PlatformFeeModel::Subscription)
        ->and($duplicate->subscription_monthly_cents)->toBe(9900)
        ->and($duplicate->productSortMode(ProductCategory::Urn)->value)->toBe('price')
        ->and($duplicate->contact_email)->toBeNull()
        ->and($duplicate->brand_primary_color)->toBeNull()
        ->and($duplicate->stripe_account_id)->toBeNull()
        ->and($duplicate->stripe_charges_enabled)->toBeFalse()
        ->and($duplicate->stripe_subscription_id)->toBeNull()
        ->and($duplicate->staff()->count())->toBe(0)
        ->and($duplicate->orders()->count())->toBe(0);
});

test('the new store needs a name and an unused subdomain', function (string $name, string $slug, string $field) {
    Livewire::test('pages::admin.stores.show', ['store' => $this->source])
        ->set('duplicateName', $name)
        ->set('duplicateSlug', $slug)
        ->call('duplicateStore')
        ->assertHasErrors($field);

    expect(Store::count())->toBe(1);
})->with([
    'missing name' => ['', 'lakeside', 'duplicateName'],
    'subdomain taken' => ['Lakeside', 'riverside', 'duplicateSlug'],
    'invalid subdomain' => ['Lakeside', 'lake side!', 'duplicateSlug'],
]);

test('the subdomain is suggested from the new name', function () {
    Livewire::test('pages::admin.stores.show', ['store' => $this->source])
        ->set('duplicateName', 'Lakeside Cremation Care')
        ->assertSet('duplicateSlug', 'lakeside-cremation-care');
});

test('a copy that fails part way leaves no products or copied images behind', function () {
    $imagePath = UploadedFile::fake()->image('urn.jpg')->store('products', 'public');
    Product::factory()->for($this->source)->create(['image_path' => $imagePath]);
    Product::factory()->for($this->source)->create(['name' => 'Second']);

    Product::saving(function (Product $product) {
        if ($product->name === 'Second' && $product->store_id !== $this->source->id) {
            throw new RuntimeException('Simulated failure');
        }
    });

    expect(fn () => app(StoreDuplicator::class)->duplicate($this->source, 'Lakeside', 'lakeside'))
        ->toThrow(RuntimeException::class, 'Simulated failure');

    $duplicate = Store::where('slug', 'lakeside')->sole();
    expect($duplicate->catalog_copy_status)->toBe(CatalogCopyStatus::Failed)
        ->and($duplicate->products()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('products'))->toBe([$imagePath]);
});

/**
 * Stands in for Laravel Cloud's object storage (Cloudflare R2), where copying
 * a file through the S3 adapter always fails because R2 has no ACLs.
 */
function useStorageThatCannotCopy(bool $writesFail = false): void
{
    $fake = Storage::disk('public');

    Storage::set('public', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $writesFail) extends FilesystemAdapter
    {
        public function __construct($driver, $adapter, array $config, private bool $writesFail)
        {
            parent::__construct($driver, $adapter, $config);
        }

        public function copy($from, $to)
        {
            return false;
        }

        public function writeStream($path, $resource, array $options = [])
        {
            return $this->writesFail ? false : parent::writeStream($path, $resource, $options);
        }
    });
}

test('product images are copied on storage that cannot copy files, like Laravel Cloud', function () {
    $imagePath = UploadedFile::fake()->image('urn.jpg')->store('products', 'public');
    Product::factory()->for($this->source)->create(['image_path' => $imagePath]);
    useStorageThatCannotCopy();

    $copiedPath = duplicateThroughAdmin($this->source)->products()->sole()->image_path;

    expect($copiedPath)->not->toBe($imagePath);
    expect(Storage::disk('public')->get($copiedPath))->toBe(Storage::disk('public')->get($imagePath));
});

test('duplicating creates the draft store right away and copies its products in the background', function () {
    Queue::fake();
    Product::factory()->for($this->source)->create();

    Livewire::test('pages::admin.stores.show', ['store' => $this->source])
        ->set('duplicateName', 'Lakeside Cremation')
        ->set('duplicateSlug', 'lakeside')
        ->call('duplicateStore')
        ->assertHasNoErrors();

    $duplicate = Store::where('slug', 'lakeside')->sole();
    expect($duplicate->catalog_copy_status)->toBe(CatalogCopyStatus::Copying)
        ->and($duplicate->products()->count())->toBe(0);
    Queue::assertPushed(CopyStoreCatalog::class, fn (CopyStoreCatalog $job) => $job->source->is($this->source) && $job->duplicate->is($duplicate));

    $page = Livewire::test('pages::admin.stores.show', ['store' => $duplicate])->assertSee('Copying products and images');

    app(StoreDuplicator::class)->copyCatalog($this->source, $duplicate);

    $page->call('checkCatalogCopy')->assertDontSee('Copying products and images');
    expect($duplicate->fresh()->catalog_copy_status)->toBeNull()
        ->and($duplicate->products()->count())->toBe(1);
});

test('a failed copy leaves the duplicate without products or stray images, and says so', function () {
    $imagePath = UploadedFile::fake()->image('urn.jpg')->store('products', 'public');
    Product::factory()->for($this->source)->create(['image_path' => $imagePath]);
    $duplicate = Store::factory()->create(['catalog_copy_status' => CatalogCopyStatus::Copying]);
    useStorageThatCannotCopy(writesFail: true);

    expect(fn () => CopyStoreCatalog::dispatchSync($this->source, $duplicate))->toThrow(RuntimeException::class);

    expect($duplicate->fresh()->catalog_copy_status)->toBe(CatalogCopyStatus::Failed)
        ->and($duplicate->products()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('products'))->toBe([$imagePath]);
    Livewire::test('pages::admin.stores.show', ['store' => $duplicate->fresh()])->assertSee('could not be copied into this store');
});
