<?php

namespace Database\Factories;

use App\Models\Residence;
use Illuminate\Database\Eloquent\Factories\Factory;

class LotFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 9999);

        return ['residence_id' => Residence::factory(), 'reference' => 'APT-'.$n, 'lot_number' => (string) $n, 'type' => 'apartment', 'occupancy_status' => 'vacant'];
    }
}
