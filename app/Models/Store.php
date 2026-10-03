<?php

namespace App\Models;

use App\Enums\PlatformFeeModel;
use App\Enums\ProductCategory;
use App\Enums\ProductSortMode;
use App\Enums\StorePath;
use App\Enums\StoreStatus;
use App\Enums\StoreUserRole;
use App\Services\BrandPalette;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property StoreStatus $status
 * @property StorePath $checkout_path
 * @property bool $requires_container
 * @property bool $requires_urn
 * @property bool $location_pricing_enabled
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $general_email
 * @property string $timezone
 * @property string|null $brand_primary_color
 * @property string|null $brand_logo_path
 * @property string|null $general_price_list_path
 * @property string|null $vital_statistics_url
 * @property string|null $stripe_account_id
 * @property bool $stripe_details_submitted
 * @property bool $stripe_charges_enabled
 * @property bool $stripe_payouts_enabled
 * @property string|null $stripe_payment_method_domain
 * @property int $platform_fee_bps
 * @property PlatformFeeModel $platform_fee_model
 * @property int $platform_fee_flat_cents
 * @property int $subscription_monthly_cents
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_subscription_id
 * @property string|null $subscription_status
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

    public const BRAND_COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    protected $attributes = [
        'checkout_path' => 'packages',
        'requires_container' => false,
        'requires_urn' => false,
        'location_pricing_enabled' => false,
        'processing_fee_enabled' => false,
        'processing_fee_bps' => 350,
        'platform_fee_model' => 'percentage',
        'platform_fee_flat_cents' => 0,
        'subscription_monthly_cents' => 0,
    ];

    protected $fillable = [
        'name',
        'slug',
        'status',
        'checkout_path',
        'requires_container',
        'requires_urn',
        'location_pricing_enabled',
        'contact_name',
        'contact_email',
        'contact_phone',
        'general_email',
        'timezone',
        'brand_primary_color',
        'brand_logo_path',
        'general_price_list_path',
        'vital_statistics_url',
        'platform_fee_bps',
        'platform_fee_model',
        'platform_fee_flat_cents',
        'subscription_monthly_cents',
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
            'location_pricing_enabled' => 'boolean',
            'stripe_details_submitted' => 'boolean',
            'stripe_charges_enabled' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            'platform_fee_bps' => 'integer',
            'platform_fee_model' => PlatformFeeModel::class,
            'platform_fee_flat_cents' => 'integer',
            'subscription_monthly_cents' => 'integer',
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
     * The host the storefront is served from, e.g. "riverside.example.com".
     */
    public function storefrontDomain(): string
    {
        return $this->slug.'.'.config('app.root_domain');
    }

    /**
     * The cities this store serves, for location-based package pricing.
     *
     * @return HasMany<StoreLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(StoreLocation::class)->orderBy('state')->orderBy('city');
    }

    /**
     * Whether package prices depend on the city the family chooses. Only in
     * effect once at least one city has been added.
     */
    public function usesLocationPricing(): bool
    {
        return $this->location_pricing_enabled && $this->locations()->exists();
    }

    /**
     * Staff logins with access to this store. Each one's role here is on
     * ->membership->role.
     *
     * @return BelongsToMany<StoreUser, $this, StoreMembership, 'membership'>
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(StoreUser::class, 'store_memberships')
            ->using(StoreMembership::class)
            ->as('membership')
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * Staff (any role) who have chosen a password and can sign in, and so
     * should hear about new orders.
     *
     * @return Collection<int, StoreUser>
     */
    public function signedUpStaff(): Collection
    {
        return $this->staff()->whereNotNull('invitation_accepted_at')->get();
    }

    /**
     * Alert the store's staff who can sign in, plus its general email (unless
     * a staff member already uses that address).
     */
    public function notifyStaff(Notification $notification): void
    {
        $staff = $this->signedUpStaff();

        if ($staff->isNotEmpty()) {
            NotificationFacade::send($staff, $notification);
        }

        $generalEmailIsStaff = $staff->contains(fn (StoreUser $member) => strcasecmp($member->email, (string) $this->general_email) === 0);

        if ($this->general_email && ! $generalEmailIsStaff) {
            NotificationFacade::route('mail', $this->general_email)->notify($notification);
        }
    }

    /**
     * Owners of this store who have chosen a password and can sign in.
     *
     * @return Collection<int, StoreUser>
     */
    public function signedUpOwners(): Collection
    {
        return $this->staff()
            ->wherePivot('role', StoreUserRole::Owner->value)
            ->whereNotNull('invitation_accepted_at')
            ->get();
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

    /**
     * Whether families fill in Vital Statistics on a form on the funeral
     * home's own website instead of ours.
     */
    public function usesExternalVitalStatistics(): bool
    {
        return filled($this->vital_statistics_url);
    }

    /**
     * Public URL of the funeral home's uploaded logo, if the store has one.
     */
    public function brandLogoUrl(): ?string
    {
        return $this->brand_logo_path
            ? Storage::disk('public')->url($this->brand_logo_path)
            : null;
    }

    /**
     * The funeral home's brand color as "#rrggbb", or null when none (or an
     * unusable value) is set. It is written into a <style> tag, so anything
     * that is not a plain hex color is ignored.
     */
    public function brandColor(): ?string
    {
        $color = $this->brand_primary_color;

        return is_string($color) && preg_match(self::BRAND_COLOR_PATTERN, $color)
            ? strtolower($color)
            : null;
    }

    /**
     * White or near-black, whichever reads better on the brand color, so a
     * light brand color still gets legible button text.
     */
    public function brandForegroundColor(): string
    {
        $color = $this->brandColor();

        return $color === null ? '#ffffff' : BrandPalette::foregroundFor($color);
    }

    /**
     * The storefront's brand shades (50–950) generated from the brand color,
     * or null to keep the platform palette.
     *
     * @return array<int, string>|null
     */
    public function brandPalette(): ?array
    {
        $color = $this->brandColor();

        return $color === null ? null : BrandPalette::fromColor($color);
    }

    public function isALaCarte(): bool
    {
        return $this->checkout_path === StorePath::ALaCarte;
    }

    public function isStripeReady(): bool
    {
        return $this->stripe_account_id !== null && $this->stripe_charges_enabled;
    }

    /**
     * The platform's cut of an order, based on the store's own revenue (the
     * subtotal) only — never on sales tax or the processing fee. A flat fee
     * is capped at the subtotal so it can never exceed what the store earns.
     */
    public function platformFeeCentsFor(int $subtotalCents): int
    {
        return match ($this->platform_fee_model) {
            PlatformFeeModel::Percentage => (int) round($subtotalCents * $this->platform_fee_bps / 10000),
            PlatformFeeModel::FlatPerOrder => min($this->platform_fee_flat_cents, $subtotalCents),
            PlatformFeeModel::Subscription, PlatformFeeModel::None => 0,
        };
    }

    /**
     * Whether the store has a platform subscription Stripe is still billing
     * (including one that is past due), as opposed to none or a canceled one.
     */
    public function hasLiveSubscription(): bool
    {
        return $this->stripe_subscription_id !== null
            && in_array($this->subscription_status, ['active', 'trialing', 'past_due', 'unpaid'], true);
    }

    public function isSubscriptionPastDue(): bool
    {
        return in_array($this->subscription_status, ['past_due', 'unpaid'], true);
    }

    /**
     * On the subscription model but not (or no longer) being billed.
     */
    public function needsSubscriptionSetup(): bool
    {
        return $this->platform_fee_model === PlatformFeeModel::Subscription && ! $this->hasLiveSubscription();
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
