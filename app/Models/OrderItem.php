<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property int|null $product_variant_id
 * @property string|null $category_snapshot
 * @property bool $is_taxable_snapshot
 * @property int|null $taxable_unit_cents_snapshot
 * @property string $name_snapshot
 * @property string|null $variant_snapshot
 * @property int $unit_price_cents
 * @property int $base_price_cents_snapshot
 * @property int $quantity
 * @property int $included_quantity
 * @property int $allowance_cents
 * @property string|null $allowance_label
 * @property int $total_price_cents
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'category_snapshot',
        'is_taxable_snapshot',
        'taxable_unit_cents_snapshot',
        'name_snapshot',
        'variant_snapshot',
        'unit_price_cents',
        'base_price_cents_snapshot',
        'quantity',
        'included_quantity',
        'allowance_cents',
        'allowance_label',
        'total_price_cents',
    ];

    protected function casts(): array
    {
        return [
            'is_taxable_snapshot' => 'boolean',
            'taxable_unit_cents_snapshot' => 'integer',
            'unit_price_cents' => 'integer',
            'base_price_cents_snapshot' => 'integer',
            'quantity' => 'integer',
            'included_quantity' => 'integer',
            'allowance_cents' => 'integer',
            'total_price_cents' => 'integer',
        ];
    }

    /**
     * What the item would have cost at its regular price, before the
     * package covered any of it.
     */
    public function regularTotalCents(): int
    {
        return $this->base_price_cents_snapshot + $this->unit_price_cents * $this->quantity;
    }

    /**
     * How much of the item the package covered (included units and allowances).
     */
    public function discountCents(): int
    {
        return max(0, $this->regularTotalCents() - $this->total_price_cents);
    }

    /**
     * E.g. "Package allowance" or "2 included with package".
     */
    public function discountLabel(): string
    {
        return match (true) {
            $this->allowance_cents > 0 => $this->allowance_label ?? __('Package allowance'),
            $this->included_quantity > 1 => __(':count included with package', ['count' => $this->included_quantity]),
            default => __('Included with package'),
        };
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
