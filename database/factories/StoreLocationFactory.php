<?php

namespace Database\Factories;

use App\Enums\UsState;
use App\Models\Store;
use App\Models\StoreLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreLocation>
 */
class StoreLocationFactory extends Factory
{
    protected $model = StoreLocation::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'state' => UsState::IL,
            'city' => $this->faker->unique()->city(),
        ];
    }
}
