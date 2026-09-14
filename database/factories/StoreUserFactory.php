<?php

namespace Database\Factories;

use App\Enums\StoreUserRole;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoreUser>
 */
class StoreUserFactory extends Factory
{
    protected $model = StoreUser::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => StoreUserRole::Owner,
            'remember_token' => Str::random(10),
        ];
    }

    public function staff(): static
    {
        return $this->state(['role' => StoreUserRole::Staff]);
    }
}
