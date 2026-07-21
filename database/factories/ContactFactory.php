<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'type' => 'individual', 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'primary_phone' => '+2126'.fake()->numerify('########'), 'preferred_language' => 'fr'];
    }
}
