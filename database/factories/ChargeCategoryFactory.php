<?php

namespace Database\Factories;

use App\Models\ChargeCategory;
use App\Models\Organization;
use App\Models\Residence;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChargeCategoryFactory extends Factory
{
    protected $model = ChargeCategory::class;

    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'residence_id' => Residence::factory(), 'name' => 'Entretien', 'code' => $this->faker->unique()->lexify('cat-????'), 'type' => 'ordinary', 'default_distribution_method' => 'equal', 'active' => true];
    }
}
