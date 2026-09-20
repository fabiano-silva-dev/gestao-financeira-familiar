<?php

namespace Database\Factories;

use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialAccount>
 */
class FinancialAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->words(2, true),
            'institution' => fake()->company(),
            'type' => fake()->randomElement(FinancialAccountType::cases()),
            'opening_balance' => fake()->randomFloat(2, -500, 10000),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
