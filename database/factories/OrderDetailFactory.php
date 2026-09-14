<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderDetail>
 */
class OrderDetailFactory extends Factory
{
    protected $model = OrderDetail::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
        ];
    }
}
