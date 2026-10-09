<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A package's price in one city, for location-priced stores.
 *
 * @property int $product_id
 * @property int $store_location_id
 * @property int $price_cents
 */
class PackageLocationPrice extends Pivot
{
    protected $table = 'package_location_prices';

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
        ];
    }
}
