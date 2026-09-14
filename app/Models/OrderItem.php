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
 * @property string $name_snapshot
 * @property string|null $variant_snapshot
 * @property int $unit_price_cents
 * @property int $quantity
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
        'name_snapshot',
        'variant_snapshot',
        'unit_price_cents',
        'quantity',
        'total_price_cents',
    ];

    protected function casts(): array
    {
        return [
            'is_taxable_snapshot' => 'boolean',
            'unit_price_cents' => 'integer',
            'quantity' => 'integer',
            'total_price_cents' => 'integer',
        ];
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
