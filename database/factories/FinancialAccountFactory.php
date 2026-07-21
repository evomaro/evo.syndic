<?php

namespace Database\Factories;

use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Residence;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinancialAccountFactory extends Factory
{
    protected $model = FinancialAccount::class;

    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'residence_id' => Residence::factory(), 'name' => 'Compte bancaire', 'code' => $this->faker->unique()->lexify('bank-????'), 'type' => 'bank', 'opening_balance_cents' => 0, 'active' => true];
    }
}
