<?php

namespace Tests\Unit;

use App\Enums\ClassificationRuleMatchType;
use App\Enums\FinancialTransactionType;
use App\Models\Category;
use App\Models\ClassificationRule;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\ClassificationRuleMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassificationRuleMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_part_of_a_pix_name(): void
    {
        $matcher = app(ClassificationRuleMatcher::class);

        $this->assertTrue($matcher->matches(
            ClassificationRuleMatchType::Contains,
            'FABIANO CARVALHO DA SILVA',
            'PIX - FABIANO CARVALHO DA SILVA',
        ));
        $this->assertFalse($matcher->matches(
            ClassificationRuleMatchType::Contains,
            'FABIANO CARVALHO DA SILVA',
            'PIX - OUTRA PESSOA',
        ));
    }

    public function test_matches_when_all_words_appear_in_any_order(): void
    {
        $matcher = app(ClassificationRuleMatcher::class);

        $this->assertTrue($matcher->matches(
            ClassificationRuleMatchType::ContainsAllWords,
            'Mercado Pago Fabiano',
            'transferencia para conta Mercado Pago Fabiano',
        ));
        $this->assertTrue($matcher->matches(
            ClassificationRuleMatchType::ContainsAllWords,
            'Mercado Pago Fabiano',
            'Fabiano enviou para Mercado Pago',
        ));
        $this->assertFalse($matcher->matches(
            ClassificationRuleMatchType::ContainsAllWords,
            'Mercado Pago Fabiano',
            'transferencia para conta Mercado Pago',
        ));
    }

    public function test_suggests_the_meaningful_part_of_a_bank_description(): void
    {
        $matcher = app(ClassificationRuleMatcher::class);
        $pix = $matcher->suggest('PIX - FABIANO CARVALHO DA SILVA', 'Fabiano');
        $transfer = $matcher->suggest('transferencia para conta Mercado Pago Fabiano');

        $this->assertSame('Fabiano', $pix['name']);
        $this->assertSame('FABIANO CARVALHO DA SILVA', $pix['pattern']);
        $this->assertSame(ClassificationRuleMatchType::Contains->value, $pix['match_type']);
        $this->assertSame('Mercado Pago Fabiano', $transfer['pattern']);
    }

    public function test_prefers_the_most_specific_active_rule_in_the_workspace(): void
    {
        $workspace = $this->workspace();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);
        ClassificationRule::factory()->for($workspace)->create([
            'name' => 'Fabiano genérico',
            'pattern' => 'Fabiano',
            'match_type' => ClassificationRuleMatchType::Contains,
            'payee_name' => 'Fabiano genérico',
            'category_id' => $category->id,
        ]);
        $specific = ClassificationRule::factory()->for($workspace)->create([
            'name' => 'PIX Fabiano',
            'pattern' => 'FABIANO CARVALHO DA SILVA',
            'match_type' => ClassificationRuleMatchType::Contains,
            'payee_name' => 'Fabiano Carvalho',
            'category_id' => $category->id,
        ]);

        $match = app(ClassificationRuleMatcher::class)->match(
            $workspace,
            'PIX - FABIANO CARVALHO DA SILVA',
        );

        $this->assertNotNull($match);
        $this->assertSame($specific->id, $match['rule_id']);
        $this->assertSame('Fabiano Carvalho', $match['payee_name']);
        $this->assertSame($category->id, $match['category_id']);
        $this->assertSame('expense', $match['action_type']);
    }

    public function test_matches_a_transfer_rule_with_the_counterpart_account(): void
    {
        $workspace = $this->workspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Mercado Pago',
        ]);
        $rule = ClassificationRule::factory()->for($workspace)->create([
            'name' => 'Mercado Pago Fabiano',
            'pattern' => 'Mercado Pago Fabiano',
            'match_type' => ClassificationRuleMatchType::ContainsAllWords,
            'action_type' => FinancialTransactionType::Transfer,
            'payee_name' => 'Fabiano',
            'category_id' => null,
            'counterpart_account_id' => $account->id,
        ]);

        $match = app(ClassificationRuleMatcher::class)->match(
            $workspace,
            'transferencia para conta Mercado Pago Fabiano',
        );

        $this->assertNotNull($match);
        $this->assertSame($rule->id, $match['rule_id']);
        $this->assertSame('transfer', $match['action_type']);
        $this->assertSame($account->id, $match['counterpart_account_id']);
        $this->assertNull($match['category_id']);
    }

    public function test_finds_an_equivalent_duplicate_ignoring_case_and_accents(): void
    {
        $workspace = $this->workspace();
        $existing = ClassificationRule::factory()->for($workspace)->create([
            'name' => 'José Silva',
            'pattern' => 'JOSÉ SILVA',
            'match_type' => ClassificationRuleMatchType::Contains,
        ]);

        $matcher = app(ClassificationRuleMatcher::class);

        $this->assertSame(
            $existing->id,
            $matcher->findDuplicate(
                $workspace,
                ClassificationRuleMatchType::Contains,
                'jose silva',
            )?->id,
        );
        $this->assertNull($matcher->findDuplicate(
            $workspace,
            ClassificationRuleMatchType::Contains,
            'jose silva',
            $existing->id,
        ));
        $this->assertNull($matcher->findDuplicate(
            $workspace,
            ClassificationRuleMatchType::Equals,
            'jose silva',
        ));
        $this->assertNull($matcher->findDuplicate(
            $workspace,
            ClassificationRuleMatchType::Contains,
            'OUTRA PESSOA',
        ));
    }

    private function workspace(): Workspace
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return $workspace;
    }
}
