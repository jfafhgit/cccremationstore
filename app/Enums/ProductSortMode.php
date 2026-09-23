<?php

namespace App\Enums;

/**
 * How a store orders the products within one category, both in the admin
 * product list and on the storefront. Custom is the only mode backed by a
 * manually-arranged column (Product::sort_order) — the others are computed
 * live from the product data so newly-added products fall into place
 * automatically instead of always landing at the end.
 */
enum ProductSortMode: string
{
    case Name = 'name';
    case Price = 'price';
    case Created = 'created';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Name => 'Name (A–Z)',
            self::Price => 'Price (low to high)',
            self::Created => 'Order entered',
            self::Custom => 'Custom (drag & drop)',
        };
    }
}
