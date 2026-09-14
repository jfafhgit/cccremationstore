<?php

namespace App\Models;

use App\Enums\StoreStatus;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property StoreStatus $status
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string $timezone
 * @property string|null $brand_primary_color
 * @property string|null $brand_logo_path
 * @property string|null $general_price_list_url
 * @property string|null $stripe_account_id
 * @property bool $stripe_details_submitted
 * @property bool $stripe_charges_enabled
 * @property bool $stripe_payouts_enabled
 * @property int $platform_fee_bps
 * @property int $tax_rate_bps
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'contact_name',
        'contact_email',
        'contact_phone',
        'timezone',
        'brand_primary_color',
        'brand_logo_path',
        'general_price_list_url',
        'platform_fee_bps',
        'tax_rate_bps',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'stripe_details_submitted' => 'boolean',
            'stripe_charges_enabled' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            'platform_fee_bps' => 'integer',
            'tax_rate_bps' => 'integer',
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
     * The store resolved for the current request by IdentifyStore, if any.
     */
    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }
}
