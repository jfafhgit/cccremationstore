<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => $this->faker->safeColorName(),
            'price_delta_cents' => 0,
        ];
    }

    /**
     * The option pre-selected for the customer on a "choose one" product.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }
}
