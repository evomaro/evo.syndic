<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'code' => fake()->unique()->bothify('ORG-###'), 'type' => 'professional_syndic', 'experience_mode' => 'pro', 'city' => 'Casablanca'];
    }

    public function essential(): static
    {
        return $this->state(fn () => ['experience_mode' => 'essential']);
    }
}
