<?php

namespace Database\Factories;

use App\Enums\MaritalStatus;
use App\Enums\Sex;
use App\Enums\UsState;
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

    /**
     * Every required Vital Statistics answer filled in and submitted.
     */
    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'next_of_kin_name' => $this->faker->name(),
            'next_of_kin_relationship' => 'Daughter',
            'next_of_kin_phone' => $this->faker->numerify('###-###-####'),
            'sex' => Sex::Female,
            'date_of_birth' => '1948-03-14',
            'birth_city' => 'Springfield',
            'birth_state' => UsState::IL,
            'date_of_death' => now()->subDays(2)->toDateString(),
            'place_of_death' => 'Home',
            'address_line1' => '12 Oak Street',
            'address_city' => 'Springfield',
            'address_state' => UsState::IL,
            'address_zip' => '62701',
            'citizenship' => 'United States',
            'occupation' => 'Teacher (retired)',
            'has_pacemaker' => false,
            'marital_status' => MaritalStatus::Widowed,
            'spouse_first_name' => 'Robert',
            'spouse_last_name' => 'Rivera',
            'mother_first_name' => 'Helen',
            'mother_maiden_name' => 'Brooks',
            'father_first_name' => 'George',
            'father_last_name' => 'Brooks',
            'veteran_status' => false,
            'submitted_at' => now(),
        ]);
    }
}
