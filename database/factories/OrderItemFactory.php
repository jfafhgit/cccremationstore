<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $unitPrice = $this->faker->numberBetween(5000, 100000);

        return [
            'order_id' => Order::factory(),
            'category_snapshot' => 'package',
            'name_snapshot' => $this->faker->words(3, true),
            'unit_price_cents' => $unitPrice,
            'quantity' => 1,
            'total_price_cents' => $unitPrice,
        ];
    }
}
