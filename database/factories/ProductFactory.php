<?php

namespace Database\Factories;

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        return [
            'store_id' => Store::factory(),
            'category' => ProductCategory::Package,
            'name' => str($name)->title(),
            'slug' => str($name)->slug(),
            'description' => $this->faker->sentence(),
            'price_cents' => $this->faker->numberBetween(15000, 500000),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function category(ProductCategory $category): static
    {
        return $this->state(['category' => $category]);
    }
}
