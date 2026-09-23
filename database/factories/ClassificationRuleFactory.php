<?php

namespace Database\Factories;

use App\Enums\ClassificationRuleMatchType;
use App\Enums\FinancialTransactionType;
use App\Models\ClassificationRule;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassificationRule>
 */
class ClassificationRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->words(2, true),
            'match_type' => ClassificationRuleMatchType::Contains,
            'pattern' => fake()->unique()->word(),
            'action_type' => FinancialTransactionType::Expense,
            'payee_name' => fake()->company(),
            'category_id' => null,
            'financial_account_id' => null,
            'counterpart_account_id' => null,
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
