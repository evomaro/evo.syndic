<?php

namespace Database\Factories;

use App\Models\Residence;
use Illuminate\Database\Eloquent\Factories\Factory;

class BuildingFactory extends Factory
{
    public function definition(): array
    {
        return ['residence_id' => Residence::factory(), 'name' => 'Immeuble '.fake()->randomLetter(), 'code' => fake()->unique()->bothify('B-##')];
    }
}
