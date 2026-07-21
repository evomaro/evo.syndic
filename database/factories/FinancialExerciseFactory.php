<?php

namespace Database\Factories;

use App\Models\FinancialExercise;
use App\Models\Organization;
use App\Models\Residence;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinancialExerciseFactory extends Factory
{
    protected $model = FinancialExercise::class;

    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'residence_id' => Residence::factory(), 'name' => 'Exercice '.$this->faker->year(), 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open'];
    }
}
