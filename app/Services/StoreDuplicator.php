<?php

namespace App\Services;

use App\Enums\CatalogCopyStatus;
use App\Enums\StoreStatus;
use App\Jobs\CopyStoreCatalog;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Creates a new draft store from an existing one, mainly to reuse its
 * product catalog.
 *
 * Settings are copied from an explicit list rather than cloning the whole
 * row, so the new store never inherits another funeral home's identity or
 * accounts: its contacts, branding, price list, Stripe account,
 * subscription, staff logins, and orders all stay behind.
 */
class StoreDuplicator
{
    /**
     * @var list<string>
     */
    public const COPIED_SETTINGS = [
        'sale_type',
        'checkout_path',
        'requires_container',
        'requires_urn',
        'requires_urn_vault',
        'preselect_container',
        'preselect_urn',
        'preselect_urn_vault',
        'offers_family_provided_container',
        'offers_family_provided_urn',
        'location_pricing_enabled',
        'timezone',
        'platform_fee_model',
        'platform_fee_bps',
        'platform_fee_flat_cents',
        'subscription_monthly_cents',
        'tax_rate_bps',
        'processing_fee_enabled',
        'processing_fee_bps',
        'settings',
    ];

    /**
     * Image files copied so far, removed again if the duplicate fails part way.
     *
     * @var list<string>
     */
    private array $copiedImagePaths = [];

    /**
     * Create the new draft store right away and queue copying its products
     * and images, which takes too long for one web request when the images
     * live in Cloud object storage.
     */
    public function duplicate(Store $source, string $name, string $slug): Store
    {
        $duplicate = Store::create([
            ...$source->only(self::COPIED_SETTINGS),
            'name' => $name,
            'slug' => $slug,
            'status' => StoreStatus::Draft,
            'catalog_copy_status' => CatalogCopyStatus::Copying,
        ]);

        CopyStoreCatalog::dispatch($source, $duplicate);

        return $duplicate;
    }

    /**
     * Copy every product (with its variants and image), the packages'
     * inclusions, and the cities served into the duplicate. All or nothing:
     * if anything fails, no products or image copies are left behind.
     */
    public function copyCatalog(Store $source, Store $duplicate): void
    {
        $this->copiedImagePaths = [];

        try {
            DB::transaction(function () use ($source, $duplicate): void {
                /** @var array<int, int> $copiedProductIds source product ID => copy's ID */
                $copiedProductIds = [];

                $source->products()->with('variants')->orderBy('id')->each(
                    function (Product $product) use ($duplicate, &$copiedProductIds): void {
                        $copiedProductIds[$product->id] = $this->copyProduct($product, $duplicate);
                    },
                );

                $this->copyPackageInclusions($source, $copiedProductIds);
                $this->copyLocations($source, $duplicate, $copiedProductIds);

                $duplicate->update(['catalog_copy_status' => null]);
            });
        } catch (Throwable $e) {
            Storage::disk('public')->delete($this->copiedImagePaths);

            throw $e;
        }
    }

    /**
     * Every product attribute is copied, so fields added to products later
     * carry over without changes here.
     */
    private function copyProduct(Product $product, Store $duplicate): int
    {
        $copy = $product->replicate();
        $copy->store_id = $duplicate->id;
        $copy->image_path = $this->copyImage($product->image_path);
        $copy->save();

        foreach ($product->variants as $variant) {
            $variantCopy = $variant->replicate();
            $variantCopy->product_id = $copy->id;
            $variantCopy->save();
        }

        return $copy->id;
    }

    /**
     * Copy the cities served along with each package's price in them.
     *
     * @param  array<int, int>  $copiedProductIds  source product ID => copy's ID
     */
    private function copyLocations(Store $source, Store $duplicate, array $copiedProductIds): void
    {
        $source->locations()->get()->each(function (StoreLocation $location) use ($duplicate, $copiedProductIds): void {
            $copy = $duplicate->locations()->create($location->only(['state', 'city']));

            DB::table('package_location_prices')
                ->where('store_location_id', $location->id)
                ->get()
                ->each(fn (object $price) => DB::table('package_location_prices')->insert([
                    'product_id' => $copiedProductIds[$price->product_id],
                    'store_location_id' => $copy->id,
                    'price_cents' => $price->price_cents,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
        });
    }

    /**
     * Point each copied package at the copies of the products it includes.
     *
     * @param  array<int, int>  $copiedProductIds  source product ID => copy's ID
     */
    private function copyPackageInclusions(Store $source, array $copiedProductIds): void
    {
        $source->products()->with('includedProducts')->each(function (Product $package) use ($copiedProductIds): void {
            if ($package->includedProducts->isEmpty()) {
                return;
            }

            Product::find($copiedProductIds[$package->id])->includedProducts()->sync(
                $package->includedProducts->mapWithKeys(fn (Product $included) => [
                    $copiedProductIds[$included->id] => ['included_quantity' => $included->pivot->included_quantity],
                ])->all(),
            );
        });
    }

    /**
     * Each store gets its own copy of an image, because replacing or deleting
     * a product's image removes the file from disk.
     *
     * The file is read and written again rather than copied: Laravel Cloud's
     * object storage (Cloudflare R2) can't copy files through the S3 adapter,
     * which asks for the file's ACL first, and R2 doesn't support ACLs.
     */
    private function copyImage(?string $path): ?string
    {
        $disk = Storage::disk('public');

        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $newPath = 'products/'.Str::random(40).($extension ? ".{$extension}" : '');

        $stream = $disk->readStream($path);
        $written = is_resource($stream) && $disk->writeStream($newPath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        if (! $written) {
            throw new RuntimeException("Could not copy the product image {$path}.");
        }

        $this->copiedImagePaths[] = $newPath;

        return $newPath;
    }
}
