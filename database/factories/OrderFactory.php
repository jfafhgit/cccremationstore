<?php

namespace Database\Factories;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderTiming;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'order_number' => Order::generateOrderNumber(),
            'status' => OrderStatus::PendingPayment,
            'source' => OrderSource::Storefront,
            'timing' => OrderTiming::Immediate,
            'purchaser_first_name' => $this->faker->firstName(),
            'purchaser_last_name' => $this->faker->lastName(),
            'purchaser_email' => $this->faker->safeEmail(),
            'purchaser_phone' => $this->faker->numerify('###-###-####'),
            'relationship_to_deceased' => 'Adult child',
            'deceased_first_name' => $this->faker->firstName(),
            'deceased_last_name' => $this->faker->lastName(),
            'currency' => 'usd',
            'subtotal_cents' => 0,
            'platform_fee_cents' => 0,
            'total_cents' => 0,
        ];
    }

    public function paid(): static
    {
        return $this->state([
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'stripe_payment_intent_id' => 'pi_'.$this->faker->bothify('##########'),
        ]);
    }
}
