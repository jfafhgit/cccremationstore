<?php

namespace App\Models;

use App\Enums\ProductCategory;
use App\Enums\ProductSortMode;
use App\Enums\StorePath;
use App\Enums\StoreStatus;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property StoreStatus $status
 * @property StorePath $checkout_path
 * @property bool $requires_container
 * @property bool $requires_urn
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string $timezone
 * @property string|null $brand_primary_color
 * @property string|null $brand_logo_path
 * @property string|null $general_price_list_path
 * @property string|null $stripe_account_id
 * @property bool $stripe_details_submitted
 * @property bool $stripe_charges_enabled
 * @property bool $stripe_payouts_enabled
 * @property int $platform_fee_bps
 * @property int $tax_rate_bps
 * @property bool $processing_fee_enabled
 * @property int $processing_fee_bps
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    protected $attributes = [
        'checkout_path' => 'packages',
        'requires_container' => false,
        'requires_urn' => false,
        'processing_fee_enabled' => false,
        'processing_fee_bps' => 350,
    ];

    protected $fillable = [
        'name',
        'slug',
        'status',
        'checkout_path',
        'requires_container',
        'requires_urn',
        'contact_name',
        'contact_email',
        'contact_phone',
        'timezone',
        'brand_primary_color',
        'brand_logo_path',
        'general_price_list_path',
        'platform_fee_bps',
        'tax_rate_bps',
        'processing_fee_enabled',
        'processing_fee_bps',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'checkout_path' => StorePath::class,
            'requires_container' => 'boolean',
            'requires_urn' => 'boolean',
            'stripe_details_submitted' => 'boolean',
            'stripe_charges_enabled' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            'platform_fee_bps' => 'integer',
            'tax_rate_bps' => 'integer',
            'processing_fee_enabled' => 'boolean',
            'processing_fee_bps' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<StoreUser, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', StoreStatus::Active);
    }

    /**
     * Public URL of the uploaded General Price List PDF, if the store has one.
     */
    public function generalPriceListUrl(): ?string
    {
        return $this->general_price_list_path
            ? Storage::disk('public')->url($this->general_price_list_path)
            : null;
    }

    public function isALaCarte(): bool
    {
        return $this->checkout_path === StorePath::ALaCarte;
    }

    public function isStripeReady(): bool
    {
        return $this->stripe_account_id !== null && $this->stripe_charges_enabled;
    }

    public function platformFeeCentsFor(int $subtotalCents): int
    {
        return (int) round($subtotalCents * $this->platform_fee_bps / 10000);
    }

    public function taxRatePercent(): float
    {
        return $this->tax_rate_bps / 100;
    }

    /**
     * How this store orders one product category's cards, both in the admin
     * product list and on the storefront. Defaults to "custom" (the
     * Product::sort_order column) so a store with no preference set keeps
     * the behavior every category had before sort modes existed.
     */
    public function productSortMode(ProductCategory $category): ProductSortMode
    {
        $value = $this->settings['product_sort'][$category->value] ?? null;

        return $value ? (ProductSortMode::tryFrom($value) ?? ProductSortMode::Custom) : ProductSortMode::Custom;
    }

    /**
     * The store resolved for the current request by IdentifyStore, if any.
     */
    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }
}
