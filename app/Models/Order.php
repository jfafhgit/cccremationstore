<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderTiming;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $store_id
 * @property string $order_number
 * @property OrderStatus $status
 * @property OrderSource $source
 * @property OrderTiming|null $timing
 * @property string|null $purchaser_first_name
 * @property string|null $purchaser_last_name
 * @property string|null $purchaser_email
 * @property string|null $purchaser_phone
 * @property string|null $relationship_to_deceased
 * @property string|null $deceased_first_name
 * @property string|null $deceased_middle_name
 * @property string|null $deceased_last_name
 * @property string|null $deceased_suffix
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $processing_fee_cents
 * @property int $platform_fee_cents
 * @property int $total_cents
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_account_id
 * @property Carbon|null $paid_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'store_id',
        'order_number',
        'status',
        'source',
        'timing',
        'purchaser_first_name',
        'purchaser_last_name',
        'purchaser_email',
        'purchaser_phone',
        'relationship_to_deceased',
        'deceased_first_name',
        'deceased_middle_name',
        'deceased_last_name',
        'deceased_suffix',
        'currency',
        'subtotal_cents',
        'tax_cents',
        'processing_fee_cents',
        'platform_fee_cents',
        'total_cents',
        'stripe_payment_intent_id',
        'stripe_account_id',
        'paid_at',
        'internal_notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->order_number ??= static::generateOrderNumber();
        });
    }

    public static function generateOrderNumber(): string
    {
        do {
            $candidate = 'TM-'.strtoupper(Str::random(8));
        } while (static::where('order_number', $candidate)->exists());

        return $candidate;
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'source' => OrderSource::class,
            'timing' => OrderTiming::class,
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'processing_fee_cents' => 'integer',
            'platform_fee_cents' => 'integer',
            'total_cents' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasOne<OrderDetail, $this>
     */
    public function detail(): HasOne
    {
        return $this->hasOne(OrderDetail::class);
    }

    #[Scope]
    protected function paid(Builder $query): Builder
    {
        return $query->whereNotNull('paid_at');
    }

    public function isAwaitingPayment(): bool
    {
        return $this->status === OrderStatus::PendingPayment && $this->paid_at === null;
    }

    public function purchaserName(): string
    {
        return trim("{$this->purchaser_first_name} {$this->purchaser_last_name}");
    }

    public function deceasedName(): string
    {
        return trim("{$this->deceased_first_name} {$this->deceased_middle_name} {$this->deceased_last_name} {$this->deceased_suffix}");
    }

    public function subtotalInDollars(): string
    {
        return number_format($this->subtotal_cents / 100, 2);
    }

    public function taxInDollars(): string
    {
        return number_format($this->tax_cents / 100, 2);
    }

    public function processingFeeInDollars(): string
    {
        return number_format($this->processing_fee_cents / 100, 2);
    }

    public function totalInDollars(): string
    {
        return number_format($this->total_cents / 100, 2);
    }

    public function routeNotificationForMail(): string
    {
        return $this->purchaser_email;
    }

    /**
     * The signed link the purchaser uses to fill in the longer, in-depth
     * intake form after payment is secured. Not time-limited, since a
     * grieving family may reasonably come back to it days later.
     */
    public function detailsUrl(): string
    {
        return URL::signedRoute('storefront.order-details', [
            'store' => $this->store->slug,
            'order' => $this,
        ]);
    }
}
