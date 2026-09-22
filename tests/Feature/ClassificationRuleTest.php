<?php

namespace Tests\Feature;

use App\Enums\ClassificationRuleMatchType;
use App\Enums\FinancialTransactionType;
use App\Models\Category;
use App\Models\ClassificationRule;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClassificationRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_classification_rules(): void
    {
        $this->get(route('classification-rules.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_only_lists_rules_from_the_current_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $visible = ClassificationRule::factory()->for($workspace)->create([
            'name' => 'PIX Fabiano',
            'pattern' => 'FABIANO CARVALHO DA SILVA',
        ]);
        ClassificationRule::factory()->for($otherWorkspace)->create([
            'name' => 'Regra de outro workspace',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('classification-rules.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('classification-rules/index')
                ->has('rules', 1)
                ->where('rules.0.id', $visible->id)
                ->where('rules.0.name', 'PIX Fabiano')
                ->where('rules.0.pattern', 'FABIANO CARVALHO DA SILVA')
            );
    }

    public function test_create_form_is_prefilled_from_a_bank_description(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('classification-rules.create', [
                'description' => 'PIX - FABIANO CARVALHO DA SILVA',
                'payee_name' => 'Fabiano Carvalho',
                'category_id' => $category->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('classification-rules/create')
                ->where('draft.name', 'Fabiano Carvalho')
                ->where('draft.pattern', 'FABIANO CARVALHO DA SILVA')
                ->where('draft.match_type', ClassificationRuleMatchType::Contains->value)
                ->where('draft.payee_name', 'Fabiano Carvalho')
                ->where('draft.category_id', $category->id)
                ->where('draft.action_type', FinancialTransactionType::Expense->value)
                ->where('draft.source_description', 'PIX - FABIANO CARVALHO DA SILVA')
                ->where('matchingRule', null)
                ->where('returnTo', null)
            );
    }

    public function test_creating_a_rule_from_reconciliation_returns_to_the_same_filters(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);
        $returnTo = '/conciliacao?account=12&period=2026-06&view=pending&focus=statement-9';
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->get(route('classification-rules.create', [
            'description' => 'PIX - FABIANO CARVALHO DA SILVA',
            'return_to' => $returnTo,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('classification-rules/create')
                ->where('returnTo', $returnTo)
            );

        $request->post(route('classification-rules.store'), [
            'name' => 'PIX Fabiano',
            'match_type' => ClassificationRuleMatchType::Contains->value,
            'pattern' => 'FABIANO CARVALHO DA SILVA',
            'action_type' => FinancialTransactionType::Expense->value,
            'category_id' => $category->id,
            'return_to' => $returnTo,
        ])
            ->assertRedirect($returnTo)
            ->assertSessionHasNoErrors();
    }

    public function test_invalid_return_to_is_ignored_when_creating_a_rule(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->get(route('classification-rules.create', [
            'return_to' => 'https://evil.test/phish',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('classification-rules/create')
                ->where('returnTo', null)
            );

        $request->post(route('classification-rules.store'), [
            'name' => 'PIX Fabiano',
            'match_type' => ClassificationRuleMatchType::Contains->value,
            'pattern' => 'FABIANO CARVALHO DA SILVA',
            'action_type' => FinancialTransactionType::Expense->value,
            'category_id' => $category->id,
            'return_to' => 'https://evil.test/phish',
        ])
            ->assertRedirect(route('classification-rules.index'))
            ->assertSessionHasNoErrors();
    }

    public function test_user_can_create_and_update_a_rule_in_the_current_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('classification-rules.store'), [
            'name' => 'Mercado Pago Fabiano',
            'match_type' => ClassificationRuleMatchType::ContainsAllWords->value,
            'pattern' => 'Mercado Pago Fabiano',
            'action_type' => FinancialTransactionType::Expense->value,
            'payee_name' => 'Mercado Pago',
            'category_id' => $category->id,
        ])
            ->assertRedirect(route('classification-rules.index'))
            ->assertSessionHasNoErrors();

        $rule = ClassificationRule::query()->sole();
        $this->assertSame($workspace->id, $rule->workspace_id);
        $this->assertSame('Mercado Pago Fabiano', $rule->pattern);
        $this->assertSame(ClassificationRuleMatchType::ContainsAllWords, $rule->match_type);

        $request->put(route('classification-rules.update', $rule), [
            'name' => 'PIX Mercado Pago',
            'match_type' => ClassificationRuleMatchType::Contains->value,
            'pattern' => 'Mercado Pago Fabiano',
            'action_type' => FinancialTransactionType::Expense->value,
            'payee_name' => 'Fabiano',
            'category_id' => $category->id,
        ])
            ->assertRedirect(route('classification-rules.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('PIX Mercado Pago', $rule->fresh()->name);
        $this->assertSame('Fabiano', $rule->fresh()->payee_name);
    }

    public function test_expense_rule_requires_category(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('classification-rules.store'), [
                'name' => 'Sem categoria',
                'match_type' => ClassificationRuleMatchType::Contains->value,
                'pattern' => 'FABIANO',
                'action_type' => FinancialTransactionType::Expense->value,
            ])
            ->assertSessionHasErrors(['category_id']);
    }

    public function test_user_can_create_a_transfer_rule_with_an_account(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Mercado Pago',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('classification-rules.store'), [
                'name' => 'PIX Fabiano',
                'match_type' => ClassificationRuleMatchType::Contains->value,
                'pattern' => 'FABIANO CARVALHO DA SILVA',
                'action_type' => FinancialTransactionType::Transfer->value,
                'payee_name' => 'Fabiano',
                'counterpart_account_id' => $account->id,
            ])
            ->assertRedirect(route('classification-rules.index'))
            ->assertSessionHasNoErrors();

        $rule = ClassificationRule::query()->sole();
        $this->assertSame(FinancialTransactionType::Transfer, $rule->action_type);
        $this->assertSame($account->id, $rule->counterpart_account_id);
        $this->assertNull($rule->category_id);
    }

    public function test_transfer_rule_requires_account(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('classification-rules.store'), [
                'name' => 'Sem conta',
                'match_type' => ClassificationRuleMatchType::Contains->value,
                'pattern' => 'Mercado Pago Fabiano',
                'action_type' => FinancialTransactionType::Transfer->value,
            ])
            ->assertSessionHasErrors(['counterpart_account_id']);
    }

    public function test_user_can_toggle_rule_status(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $rule = ClassificationRule::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('classification-rules.toggle-status', $rule))
            ->assertRedirect(route('classification-rules.index'));

        $this->assertFalse($rule->fresh()->is_active);
    }

    public function test_cannot_create_a_duplicate_rule_with_an_equivalent_pattern(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);
        ClassificationRule::factory()->for($workspace)->create([
            'name' => 'PIX Fabiano',
            'match_type' => ClassificationRuleMatchType::Contains,
            'pattern' => 'FABIANO CARVALHO DA SILVA',
            'category_id' => $category->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('classification-rules.store'), [
                'name' => 'Fabiano de novo',
                'match_type' => ClassificationRuleMatchType::Contains->value,
                'pattern' => 'fabiano carvalho da silva',
                'action_type' => FinancialTransactionType::Expense->value,
                'category_id' => $category->id,
            ])
            ->assertSessionHasErrors(['pattern']);

        $this->assertDatabaseCount('classification_rules', 1);
    }

    public function test_can_create_a_more_specific_rule_when_a_broader_one_exists(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);
        ClassificationRule::factory()->for($workspace)->create([
            'name' => 'Fabiano',
            'match_type' => ClassificationRuleMatchType::Contains,
            'pattern' => 'Fabiano',
            'category_id' => $category->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('classification-rules.store'), [
                'name' => 'PIX Fabiano',
                'match_type' => ClassificationRuleMatchType::Contains->value,
                'pattern' => 'FABIANO CARVALHO DA SILVA',
                'action_type' => FinancialTransactionType::Expense->value,
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('classification-rules.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('classification_rules', 2);
    }

    public function test_cannot_update_a_rule_into_a_duplicate_of_another(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);
        ClassificationRule::factory()->for($workspace)->create([
            'name' => 'PIX Fabiano',
            'pattern' => 'FABIANO CARVALHO DA SILVA',
            'match_type' => ClassificationRuleMatchType::Contains,
            'category_id' => $category->id,
        ]);
        $other = ClassificationRule::factory()->for($workspace)->create([
            'name' => 'Mercado Pago',
            'pattern' => 'Mercado Pago Fabiano',
            'match_type' => ClassificationRuleMatchType::Contains,
            'category_id' => $category->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('classification-rules.update', $other), [
                'name' => 'Mercado Pago',
                'match_type' => ClassificationRuleMatchType::Contains->value,
                'pattern' => 'fabiano carvalho da silva',
                'action_type' => FinancialTransactionType::Expense->value,
                'category_id' => $category->id,
            ])
            ->assertSessionHasErrors(['pattern']);

        $this->assertSame('Mercado Pago Fabiano', $other->fresh()->pattern);
    }

    public function test_create_form_points_to_the_existing_rule_for_a_description(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);
        $rule = ClassificationRule::factory()->for($workspace)->create([
            'name' => 'PIX Fabiano',
            'match_type' => ClassificationRuleMatchType::Contains,
            'pattern' => 'FABIANO CARVALHO DA SILVA',
            'category_id' => $category->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('classification-rules.create', [
                'description' => 'PIX - FABIANO CARVALHO DA SILVA',
                'payee_name' => 'Fabiano Carvalho',
                'category_id' => $category->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('classification-rules/create')
                ->where('matchingRule.id', $rule->id)
                ->where('matchingRule.name', 'PIX Fabiano')
                ->where('matchingRule.pattern', 'FABIANO CARVALHO DA SILVA')
            );
    }

    /** @return array{User, Workspace} */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
