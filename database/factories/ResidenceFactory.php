<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResidenceFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'name' => 'Résidence '.fake()->streetName(), 'code' => fake()->unique()->bothify('RES-###'), 'address_line_1' => fake()->streetAddress(), 'city' => fake()->randomElement(['Casablanca', 'Rabat', 'Marrakech', 'Tanger'])];
    }
}
