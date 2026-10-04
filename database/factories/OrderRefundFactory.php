<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderRefund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderRefund>
 */
class OrderRefundFactory extends Factory
{
    protected $model = OrderRefund::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->paid(),
            'stripe_refund_id' => 're_'.$this->faker->unique()->bothify('##########'),
            'amount_cents' => 10000,
            'status' => 'succeeded',
        ];
    }
}
