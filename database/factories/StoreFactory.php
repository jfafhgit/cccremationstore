<?php

namespace Database\Factories;

use App\Enums\StoreStatus;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $name = $this->faker->company().' Cremation Care';

        return [
            'name' => $name,
            'slug' => str($name.'-'.$this->faker->unique()->numberBetween(1000, 9999))->slug(),
            'status' => StoreStatus::Active,
            'contact_name' => $this->faker->name(),
            'contact_email' => $this->faker->companyEmail(),
            'contact_phone' => $this->faker->numerify('###-###-####'),
            'timezone' => 'America/New_York',
            'platform_fee_bps' => 500,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => StoreStatus::Draft]);
    }

    public function requiresContainer(): static
    {
        return $this->state(['requires_container' => true]);
    }

    public function requiresUrn(): static
    {
        return $this->state(['requires_urn' => true]);
    }

    public function stripeConnected(): static
    {
        return $this->state([
            'stripe_account_id' => 'acct_'.$this->faker->bothify('##########'),
            'stripe_details_submitted' => true,
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
        ]);
    }
}
