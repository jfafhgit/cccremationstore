<?php

namespace App\Services;

use App\Enums\StoreStatus;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        'checkout_path',
        'requires_container',
        'requires_urn',
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

    public function duplicate(Store $source, string $name, string $slug): Store
    {
        $this->copiedImagePaths = [];

        try {
            return DB::transaction(function () use ($source, $name, $slug): Store {
                $duplicate = Store::create([
                    ...$source->only(self::COPIED_SETTINGS),
                    'name' => $name,
                    'slug' => $slug,
                    'status' => StoreStatus::Draft,
                ]);

                $source->products()->with('variants')->orderBy('id')->each(
                    fn (Product $product) => $this->copyProduct($product, $duplicate),
                );

                return $duplicate;
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
    private function copyProduct(Product $product, Store $duplicate): void
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
    }

    /**
     * Each store gets its own copy of an image, because replacing or deleting
     * a product's image removes the file from disk.
     */
    private function copyImage(?string $path): ?string
    {
        $disk = Storage::disk('public');

        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $newPath = 'products/'.Str::random(40).($extension ? ".{$extension}" : '');

        $disk->copy($path, $newPath);
        $this->copiedImagePaths[] = $newPath;

        return $newPath;
    }
}
