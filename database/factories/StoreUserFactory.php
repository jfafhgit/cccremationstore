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
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'invitation_accepted_at' => now(),
        ];
    }

    /**
     * Give the login access to a store (as its owner unless told otherwise).
     * Chain it again for each extra location.
     */
    public function forStore(Store $store, StoreUserRole $role = StoreUserRole::Owner): static
    {
        return $this->hasAttached($store, ['role' => $role->value], 'stores');
    }

    /**
     * Invited by email but has not chosen a password yet.
     */
    public function invited(string $token = 'invitation-token'): static
    {
        return $this->state([
            'password' => Hash::make(Str::random(40)),
            'invitation_token' => hash('sha256', $token),
            'invited_at' => now(),
            'invitation_accepted_at' => null,
        ]);
    }
}
