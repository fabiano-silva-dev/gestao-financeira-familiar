<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\CreditCard;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCard>
 */
class CreditCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->randomElement(['Nubank', 'Visa Platinum', 'Mastercard Gold']),
            'institution' => fake()->company(),
            'last_four' => fake()->numerify('####'),
            'holder_id' => null,
            'credit_limit' => fake()->randomFloat(2, 500, 50000),
            'closing_day' => fake()->numberBetween(1, 28),
            'due_day' => fake()->numberBetween(1, 28),
            'payment_account_id' => null,
            'invoice_payment_method' => PaymentMethod::Boleto,
            'payment_instructions' => null,
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
