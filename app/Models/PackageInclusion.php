<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A product a package includes: how many units it covers (0 covers only a
 * per-unit item's base fee) or, for a choose-one item, which option.
 *
 * @property int $package_id
 * @property int $product_id
 * @property int $included_quantity
 * @property int|null $included_variant_id
 */
class PackageInclusion extends Pivot
{
    protected $table = 'package_included_products';

    protected function casts(): array
    {
        return [
            'included_quantity' => 'integer',
            'included_variant_id' => 'integer',
        ];
    }
}
