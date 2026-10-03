<?php

namespace App\Models;

use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property string|null $description
 * @property int $price_delta_cents
 * @property bool $is_default
 * @property string|null $sku
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'description',
        'price_delta_cents',
        'is_default',
        'sku',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
