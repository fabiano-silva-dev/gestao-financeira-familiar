<?php

namespace Tests\Feature;

use App\Enums\AccountMovementType;
use App\Enums\CategoryType;
use App\Enums\ClassificationRuleMatchType;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\Category;
use App\Models\ClassificationRule;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Services\Finance\TransferService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_guest_cannot_access_reconciliation(): void
    {
        $this->get(route('reconciliation.index'))
            ->assertRedirect(route('login'));
    }

    public function test_page_prioritizes_compatible_movement_by_date_and_description(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
        ]);
        $otherAccount = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-89.90',
            '2026-09-10',
            'Energia elétrica',
        );
        $best = $this->movement(
            $workspace,
            $account,
            '-89.90',
            '2026-09-10',
            'Energia elétrica',
            AccountMovementType::ExpensePayment,
        );
        $this->movement(
            $workspace,
            $account,
            '-89.90',
            '2026-08-01',
            'Outra despesa',
        );
        $this->movement($workspace, $account, '89.90', '2026-09-10', 'Crédito oposto');
        $this->movement($workspace, $otherAccount, '-89.90', '2026-09-10', 'Conta errada');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reconciliation/index')
                ->has('entries', 1)
                ->where('entries.0.id', $entry->id)
                ->where('entries.0.kind', 'statement')
                ->where('entries.0.candidates.0.movement_id', $best->id)
                ->where('entries.0.candidates.0.confidence', 'high')
                ->where('entries.0.candidates.0.is_suggestion', true)
                ->has('entries.0.candidates', 1)
                ->where('pendingEntriesCount', 1)
                ->where('unmatchedMovementsCount', 4)
            );
    }

    public function test_user_can_reconcile_matching_bank_and_account_movements(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-150.00');
        $movement = $this->movement($workspace, $account, '-150.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.store', $entry), [
                'account_movement_id' => $movement->id,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $movement->refresh();
        $this->assertTrue($entry->is_reconciled);
        $this->assertSame($movement->id, $entry->account_movement_id);
        $this->assertSame($user->id, $entry->reconciled_by);
        $this->assertNotNull($entry->reconciled_at);
        $this->assertTrue($movement->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('account_movements', 1);
    }

    public function test_reconciliation_requires_same_account_and_signed_amount(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $otherAccount = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-100.00');
        $wrongAccount = $this->movement($workspace, $otherAccount, '-100.00');
        $wrongAmount = $this->movement($workspace, $account, '-99.99');
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.store', $entry), [
            'account_movement_id' => $wrongAccount->id,
        ])->assertSessionHasErrors('account_movement_id');

        $request->post(route('reconciliation.store', $entry), [
            'account_movement_id' => $wrongAmount->id,
        ])->assertSessionHasErrors('account_movement_id');

        $this->assertFalse($entry->fresh()->is_reconciled);
        $this->assertFalse($wrongAccount->fresh()->is_reconciled);
        $this->assertFalse($wrongAmount->fresh()->is_reconciled);
    }

    public function test_same_account_movement_cannot_be_used_twice(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $firstEntry = $this->bankEntry($workspace, $account, '-75.00', '2026-09-10');
        $secondEntry = $this->bankEntry($workspace, $account, '-75.00', '2026-09-11');
        $movement = $this->movement($workspace, $account, '-75.00');
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.store', $firstEntry), [
            'account_movement_id' => $movement->id,
        ])->assertSessionHasNoErrors();
        $request->post(route('reconciliation.store', $secondEntry), [
            'account_movement_id' => $movement->id,
        ])->assertSessionHasErrors('account_movement_id');

        $this->assertTrue($firstEntry->fresh()->is_reconciled);
        $this->assertFalse($secondEntry->fresh()->is_reconciled);
    }

    public function test_user_can_undo_reconciliation(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '2500.00');
        $movement = $this->movement(
            $workspace,
            $account,
            '2500.00',
            type: AccountMovementType::IncomeReceipt,
        );
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.store', $entry), [
            'account_movement_id' => $movement->id,
        ])->assertSessionHasNoErrors();
        $request->delete(route('reconciliation.destroy', $entry))
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertFalse($entry->is_reconciled);
        $this->assertNull($entry->account_movement_id);
        $this->assertNull($entry->reconciled_by);
        $this->assertNull($entry->reconciled_at);
        $this->assertFalse($movement->fresh()->is_reconciled);
    }

    public function test_each_side_of_a_transfer_is_reconciled_with_its_own_bank_entry(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create();
        $destination = FinancialAccount::factory()->for($workspace)->create();
        $transfer = app(TransferService::class)->create($workspace, [
            'transaction_date' => '2026-09-10',
            'description' => 'Transferência para reserva',
            'amount' => '300.00',
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);
        $outgoing = $transfer->accountMovements()
            ->where('type', AccountMovementType::TransferOut)
            ->sole();
        $incoming = $transfer->accountMovements()
            ->where('type', AccountMovementType::TransferIn)
            ->sole();
        $sourceEntry = $this->bankEntry($workspace, $source, '-300.00');
        $destinationEntry = $this->bankEntry($workspace, $destination, '300.00');
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.store', $sourceEntry), [
            'account_movement_id' => $outgoing->id,
        ])->assertSessionHasNoErrors();
        $request->post(route('reconciliation.store', $destinationEntry), [
            'account_movement_id' => $incoming->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($outgoing->id, $sourceEntry->fresh()->account_movement_id);
        $this->assertSame($incoming->id, $destinationEntry->fresh()->account_movement_id);
        $this->assertTrue($outgoing->fresh()->is_reconciled);
        $this->assertTrue($incoming->fresh()->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('account_movements', 2);
    }

    public function test_destination_statement_suggests_existing_transfer_on_same_day_and_amount(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create();
        $destination = FinancialAccount::factory()->for($workspace)->create();
        $transfer = app(TransferService::class)->create($workspace, [
            'transaction_date' => '2026-09-10',
            'description' => 'Transferência para reserva',
            'amount' => '300.00',
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);
        $incoming = $transfer->accountMovements()
            ->where('type', AccountMovementType::TransferIn)
            ->sole();
        $destinationEntry = $this->bankEntry(
            $workspace,
            $destination,
            '300.00',
            '2026-09-10',
            'PIX RECEBIDO - CONTA ORIGEM',
        );
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->get(route('reconciliation.index', $this->workbenchQuery($destination)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 1)
                ->where('entries.0.id', $destinationEntry->id)
                ->where('entries.0.has_suggestion', true)
                ->where('entries.0.related_is_transfer', true)
                ->where('entries.0.candidates.0.movement_id', $incoming->id)
                ->where('entries.0.candidates.0.is_suggestion', true)
            );

        $request->post(route('reconciliation.create', $destinationEntry))
            ->assertSessionHasErrors('entry');
        $request->post(route('reconciliation.transfer', $destinationEntry), [
            'counterpart_account_id' => $source->id,
        ])->assertSessionHasErrors('entry');

        $this->assertFalse($destinationEntry->fresh()->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('account_movements', 2);
    }

    public function test_distant_confirmed_transfer_does_not_block_a_new_transfer(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create();
        $destination = FinancialAccount::factory()->for($workspace)->create();
        $other = FinancialAccount::factory()->for($workspace)->create();
        app(TransferService::class)->create($workspace, [
            'transaction_date' => '2026-07-13',
            'description' => 'PIX - FABIANO CARVALHO DA SILVA',
            'amount' => '1500.00',
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);
        $entry = $this->bankEntry(
            $workspace,
            $destination,
            '1500.00',
            '2026-09-04',
            'Dinheiro retirado Despesas Mensais',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.transfer', $entry), [
                'counterpart_account_id' => $other->id,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($entry->fresh()->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 2);
    }

    public function test_planned_transfer_within_the_wide_window_still_blocks_a_new_transfer(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create();
        $destination = FinancialAccount::factory()->for($workspace)->create();
        app(TransferService::class)->create($workspace, [
            'transaction_date' => '2026-08-01',
            'description' => 'Reserva planejada',
            'amount' => '300.00',
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'status' => FinancialTransactionStatus::Planned->value,
            'notes' => null,
        ]);
        $entry = $this->bankEntry($workspace, $destination, '300.00', '2026-09-10', 'PIX recebido');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.transfer', $entry), [
                'counterpart_account_id' => $source->id,
            ])
            ->assertSessionHasErrors('entry');

        $this->assertFalse($entry->fresh()->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_early_payment_suggests_the_planned_expense_instead_of_an_old_transfer(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $other = FinancialAccount::factory()->for($workspace)->create();
        $rent = app(FinancialEntryService::class)->create($workspace, [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-10',
            'competence_date' => '2026-09-01',
            'description' => 'Aluguel',
            'amount' => '2000.00',
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix->value,
            'payee_name' => null,
            'payment_instructions' => null,
            'due_date' => '2026-09-10',
            'settled_on' => null,
            'status' => FinancialTransactionStatus::Planned->value,
            'notes' => null,
        ]);
        app(TransferService::class)->create($workspace, [
            'transaction_date' => '2026-07-03',
            'description' => 'PIX antigo',
            'amount' => '2000.00',
            'source_account_id' => $account->id,
            'destination_account_id' => $other->id,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-2000.00',
            '2026-09-05',
            'PIX aluguel',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.candidates.0.description', 'Aluguel')
                ->where('entries.0.candidates.0.is_planned', true)
                ->where('entries.0.candidates.0.is_suggestion', true)
                ->where('entries.0.candidates.0.transaction_id', $rent->id)
                ->has('entries.0.candidates', 1)
            );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.store', $entry), [
                'financial_transaction_id' => $rent->id,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $rent->refresh();
        $entry->refresh();
        $this->assertTrue($entry->is_reconciled);
        $this->assertSame(FinancialTransactionStatus::Confirmed, $rent->status);
        $this->assertSame('2026-09-05', $rent->settled_on->toDateString());
        $this->assertSame('2026-09-10', $rent->transaction_date->toDateString());
        $this->assertSame('2026-09-05', $entry->accountMovement?->occurred_on->toDateString());
        $this->assertDatabaseCount('financial_transactions', 2);
    }

    public function test_movement_from_another_workspace_cannot_be_reconciled(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-30.00');
        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();
        $otherMovement = $this->movement($otherWorkspace, $otherAccount, '-30.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.store', $entry), [
                'account_movement_id' => $otherMovement->id,
            ])
            ->assertSessionHasErrors('account_movement_id');

        $this->assertFalse($entry->fresh()->is_reconciled);
    }

    public function test_reconciled_movement_must_be_unlinked_before_financial_change(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-45.00');
        $movement = $this->movement($workspace, $account, '-45.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.store', $entry), [
                'account_movement_id' => $movement->id,
            ])
            ->assertSessionHasNoErrors();

        try {
            $movement->refresh()->update(['amount' => '-46.00']);
            $this->fail('A alteração deveria exigir a remoção da conciliação.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reconciliation', $exception->errors());
        }

        $this->assertSame('-45.00', $movement->fresh()->amount);
        $this->assertTrue($entry->fresh()->is_reconciled);
    }

    public function test_page_exposes_workbench_fields_and_view_filters(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
        ]);
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-89.90',
            '2026-09-10',
            'Energia elétrica',
        );
        $this->movement(
            $workspace,
            $account,
            '-89.90',
            '2026-09-10',
            'Energia elétrica',
            AccountMovementType::ExpensePayment,
        );
        $duplicate = $this->bankEntry(
            $workspace,
            $account,
            '-89.90',
            '2026-09-10',
            'Energia eletrica',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reconciliation/index')
                ->where('entries.0.relation_path', 'Movimento bancário → Nubank Fabiano')
                ->where('entries.0.has_suggestion', true)
                ->where('viewCounts.pending', 2)
                ->where('viewCounts.suggestions', 2)
                ->where('filters.view', 'all')
                ->where('scopeReady', true)
            );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account, [
                'view' => 'duplicates',
            ])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.view', 'duplicates')
                ->has('entries', 2)
                ->where('entries.0.is_possible_duplicate', true)
            );

        $this->assertFalse($duplicate->fresh()->is_reconciled);
        $this->assertFalse($entry->fresh()->is_reconciled);
    }

    public function test_user_can_ignore_bank_entry_without_creating_a_transaction(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-40.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('reconciliation.ignore', $entry))
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($entry->fresh()->is_ignored);
        $this->assertDatabaseCount('financial_transactions', 0);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 0)
                ->where('pendingEntriesCount', 0)
            );
    }

    public function test_user_can_create_bank_transaction_from_unmatched_entry(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create([
            'name' => 'Farmácia',
            'type' => CategoryType::Expense->value,
        ]);
        $entry = $this->bankEntry($workspace, $account, '-120.00', description: 'Farmácia Raia');
        $entry->update(['suggested_category_id' => $category->id]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.create', $entry))
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertTrue($entry->is_reconciled);
        $this->assertSame($category->id, $entry->accountMovement?->transaction?->category_id);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('account_movements', 1);
        $this->assertSame(
            FinancialTransactionType::Expense->value,
            $entry->accountMovement?->transaction?->type->value,
        );
    }

    public function test_create_bank_transaction_stays_pending_without_category(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '0.04', description: 'Rendimentos');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.create', $entry))
            ->assertSessionHasErrors('category_id');

        $this->assertFalse($entry->fresh()->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_pix_from_third_party_is_not_treated_as_own_account_transfer(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '150.00',
            description: 'PIX RECEBIDO - USE G CLOSET MODA FEMININA LTDA',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.is_likely_transfer', false)
                ->where('viewCounts.transfers', 0)
            );

        $category = Category::factory()->for($workspace)->create([
            'name' => 'Rendimentos',
            'type' => CategoryType::Income->value,
        ]);
        $entry->update(['suggested_category_id' => $category->id]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.create', $entry))
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertTrue($entry->is_reconciled);
        $this->assertSame(
            FinancialTransactionType::Income->value,
            $entry->accountMovement?->transaction?->type->value,
        );
    }

    public function test_create_is_blocked_when_a_compatible_movement_already_exists(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-75.00', description: 'Energia elétrica');
        $this->movement(
            $workspace,
            $account,
            '-75.00',
            '2026-09-10',
            'Energia elétrica',
            AccountMovementType::ExpensePayment,
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.create', $entry))
            ->assertSessionHasErrors('entry');

        $this->assertFalse($entry->fresh()->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_invoice_payment_cannot_be_created_as_expense(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-800.00',
            description: 'Pagamento de fatura Nubank',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.create', $entry))
            ->assertSessionHasErrors('entry');

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_user_can_mark_bank_entry_as_transfer_between_own_accounts(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create();
        $destination = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $source, '-300.00', description: 'TED reserva');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.transfer', $entry), [
                'counterpart_account_id' => $destination->id,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertTrue($entry->is_reconciled);
        $this->assertSame(
            FinancialTransactionType::Transfer->value,
            $entry->accountMovement?->transaction?->type->value,
        );
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('account_movements', 2);
    }

    public function test_user_can_classify_bank_entry_without_changing_origin(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create(['name' => 'Moradia']);
        $entry = $this->bankEntry($workspace, $account, '-89.90', description: 'CEEE');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('reconciliation.classify', $entry), [
                'payee_name' => 'RGE Sul',
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame('RGE Sul', $entry->suggested_payee_name);
        $this->assertSame($category->id, $entry->suggested_category_id);
        $this->assertSame('CEEE', $entry->description);
        $this->assertFalse($entry->is_reconciled);
    }

    public function test_workbench_suggests_classification_from_a_rule(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);
        $rule = ClassificationRule::factory()->for($workspace)->create([
            'name' => 'PIX Fabiano',
            'match_type' => ClassificationRuleMatchType::Contains,
            'pattern' => 'FABIANO CARVALHO DA SILVA',
            'action_type' => FinancialTransactionType::Expense,
            'payee_name' => 'Fabiano Carvalho',
            'category_id' => $category->id,
        ]);
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-150.00',
            description: 'PIX - FABIANO CARVALHO DA SILVA',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.id', $entry->id)
                ->where('entries.0.matcher_payee_name', 'Fabiano Carvalho')
                ->where('entries.0.matcher_action_type', 'expense')
                ->where('entries.0.matcher_category_id', $category->id)
                ->where('entries.0.matcher_category_name', 'Transferências')
                ->where('entries.0.matcher_rule_id', $rule->id)
                ->where('entries.0.is_likely_transfer', false)
            );
    }

    public function test_creating_a_transaction_uses_matching_classification_rule(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create(['name' => 'Mercado']);
        ClassificationRule::factory()->for($workspace)->create([
            'name' => 'Stangherlin',
            'match_type' => ClassificationRuleMatchType::Contains,
            'pattern' => 'Stangherlin',
            'payee_name' => 'Stangherlin',
            'category_id' => $category->id,
        ]);
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-89.90',
            description: 'Stangherlin Supermercado Centro',
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.create', $entry))
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHasNoErrors();

        $transaction = $entry->fresh()->accountMovement?->transaction;
        $this->assertSame('Stangherlin', $transaction?->payee_name);
        $this->assertSame($category->id, $transaction?->category_id);
    }

    public function test_reconciled_entry_exposes_the_matching_rule_so_the_prompt_can_be_skipped(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create(['name' => 'Transferências']);
        $rule = ClassificationRule::factory()->for($workspace)->create([
            'name' => 'PIX Fabiano',
            'match_type' => ClassificationRuleMatchType::Contains,
            'pattern' => 'FABIANO CARVALHO DA SILVA',
            'action_type' => FinancialTransactionType::Expense,
            'payee_name' => 'Fabiano Carvalho',
            'category_id' => $category->id,
        ]);
        $movement = $this->movement(
            $workspace,
            $account,
            '-150.00',
            '2026-09-10',
            'PIX - FABIANO CARVALHO DA SILVA',
        );
        $entry = $this->bankEntry(
            $workspace,
            $account,
            '-150.00',
            '2026-09-10',
            'PIX - FABIANO CARVALHO DA SILVA',
        );
        $entry->update([
            'account_movement_id' => $movement->id,
            'is_reconciled' => true,
            'reconciled_by' => $user->id,
            'reconciled_at' => now(),
        ]);
        $movement->update(['is_reconciled' => true]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account, [
                'view' => 'reconciled',
            ])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 1)
                ->where('entries.0.id', $entry->id)
                ->where('entries.0.is_reconciled', true)
                ->where('entries.0.matcher_rule_id', $rule->id)
            );
    }

    public function test_page_requires_account_and_period_or_import_before_listing(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-89.90');
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->get(route('reconciliation.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reconciliation/index')
                ->where('scopeReady', false)
                ->has('entries', 0)
            );

        $request->get(route('reconciliation.index', ['account' => $account->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scopeReady', false)
                ->has('entries', 0)
            );

        $request->get(route('reconciliation.index', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scopeReady', false)
                ->has('entries', 0)
            );

        $request->get(route('reconciliation.index', $this->workbenchQuery($account)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scopeReady', true)
                ->has('entries', 1)
                ->where('entries.0.id', $entry->id)
            );

        $request->get(route('reconciliation.index', [
            'import' => $entry->financial_import_id,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scopeReady', true)
                ->has('entries', 1)
                ->where('entries.0.id', $entry->id)
                ->where('entries.0.import_id', $entry->financial_import_id)
            );
    }

    public function test_scoped_page_shows_already_reconciled_entries_marked(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $pending = $this->bankEntry($workspace, $account, '-40.00', '2026-09-10', 'Padaria');
        $movement = $this->movement($workspace, $account, '-90.00', '2026-09-12', 'Farmácia');
        $reconciled = $this->bankEntry($workspace, $account, '-90.00', '2026-09-12', 'Farmácia');
        $reconciled->update([
            'account_movement_id' => $movement->id,
            'is_reconciled' => true,
            'reconciled_by' => $user->id,
            'reconciled_at' => now(),
        ]);
        $movement->update(['is_reconciled' => true]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('reconciliation.index', $this->workbenchQuery($account)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scopeReady', true)
                ->has('entries', 2)
                ->where('viewCounts.all', 2)
                ->where('viewCounts.pending', 1)
                ->where('viewCounts.reconciled', 1)
                ->where(
                    'entries',
                    fn ($entries): bool => collect($entries)->contains(
                        fn (array $entry): bool => $entry['id'] === $reconciled->id
                            && $entry['is_reconciled'] === true,
                    ) && collect($entries)->contains(
                        fn (array $entry): bool => $entry['id'] === $pending->id
                            && $entry['is_reconciled'] === false,
                    ),
                )
            );
    }

    public function test_guest_cannot_reprocess_an_import(): void
    {
        $this->post(route('reconciliation.reprocess', 1))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_reopen_and_reprocess_a_bank_import(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '-150.00', description: 'Energia elétrica');
        $movement = $this->movement(
            $workspace,
            $account,
            '-150.00',
            '2026-09-10',
            'Energia elétrica',
            AccountMovementType::ExpensePayment,
        );
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('reconciliation.store', $entry), [
            'account_movement_id' => $movement->id,
        ])->assertSessionHasNoErrors();

        $ignored = $this->bankEntry($workspace, $account, '-12.00', description: 'Tarifa');
        $ignored->update(['financial_import_id' => $entry->financial_import_id]);
        $request->patch(route('reconciliation.ignore', $ignored))
            ->assertSessionHasNoErrors();

        $request->post(route('reconciliation.reprocess', [
            'import' => $entry->financial_import_id,
        ]), [
            'import' => $entry->financial_import_id,
        ])
            ->assertRedirect(route('reconciliation.index', [
                'import' => $entry->financial_import_id,
            ]))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $ignored->refresh();
        $movement->refresh();
        $this->assertTrue($entry->is_reconciled);
        $this->assertSame($movement->id, $entry->account_movement_id);
        $this->assertTrue($movement->is_reconciled);
        $this->assertFalse($ignored->is_ignored);
        $this->assertFalse($ignored->is_reconciled);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('account_movements', 1);
    }

    public function test_reprocessing_a_bank_import_applies_a_new_classification_rule(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create(['name' => 'Moradia']);
        $entry = $this->bankEntry($workspace, $account, '-89.90', description: 'PIX CEEE');
        ClassificationRule::factory()->for($workspace)->create([
            'name' => 'Energia',
            'match_type' => ClassificationRuleMatchType::Contains,
            'pattern' => 'CEEE',
            'action_type' => FinancialTransactionType::Expense,
            'payee_name' => 'RGE Sul',
            'category_id' => $category->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.reprocess', $entry->financial_import_id))
            ->assertRedirect(route('reconciliation.index', [
                'import' => $entry->financial_import_id,
            ]))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertTrue($entry->is_reconciled);
        $this->assertSame('RGE Sul', $entry->suggested_payee_name);
        $this->assertSame($category->id, $entry->suggested_category_id);
        $this->assertSame(
            $category->id,
            $entry->accountMovement?->transaction?->category_id,
        );
        $this->assertSame('RGE Sul', $entry->accountMovement?->transaction?->payee_name);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_reprocessing_keeps_uncategorized_bank_income_pending(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $entry = $this->bankEntry($workspace, $account, '0.04', description: 'Rendimentos');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.reprocess', $entry->financial_import_id))
            ->assertRedirect(route('reconciliation.index', [
                'import' => $entry->financial_import_id,
            ]))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertFalse($entry->is_reconciled);
        $this->assertNull($entry->account_movement_id);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_import_from_another_workspace_cannot_be_reprocessed(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();
        $entry = $this->bankEntry($otherWorkspace, $otherAccount, '-50.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('reconciliation.reprocess', $entry->financial_import_id))
            ->assertNotFound();
    }

    /** @return array{User, Workspace} */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }

    /**
     * @param  array<string, int|string>  $extra
     * @return array<string, int|string>
     */
    private function workbenchQuery(FinancialAccount $account, array $extra = []): array
    {
        return [
            'account' => $account->id,
            'period' => '2026-09',
            ...$extra,
        ];
    }

    private function bankEntry(
        Workspace $workspace,
        FinancialAccount $account,
        string $amount,
        string $occurredOn = '2026-09-10',
        string $description = 'Movimento bancário',
    ): BankStatementEntry {
        $this->sequence++;
        $suffix = str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT);
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'type' => FinancialImportType::Ofx,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => "extrato-{$suffix}.ofx",
            'file_hash' => hash('sha256', "file-{$workspace->id}-{$suffix}"),
            'deduplication_key' => hash('sha256', "import-{$workspace->id}-{$suffix}"),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'imported_at' => now(),
        ]);

        return BankStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'financial_account_id' => $account->id,
            'external_id' => "fit-{$suffix}",
            'deduplication_key' => hash('sha256', "entry-{$workspace->id}-{$suffix}"),
            'occurred_on' => $occurredOn,
            'amount' => $amount,
            'transaction_type' => str_starts_with($amount, '-') ? 'DEBIT' : 'CREDIT',
            'description' => $description,
            'memo' => null,
            'is_reconciled' => false,
        ]);
    }

    private function movement(
        Workspace $workspace,
        FinancialAccount $account,
        string $amount,
        string $occurredOn = '2026-09-10',
        string $description = 'Lançamento financeiro',
        AccountMovementType $type = AccountMovementType::Adjustment,
    ): AccountMovement {
        return AccountMovement::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'occurred_on' => $occurredOn,
            'description' => $description,
            'amount' => $amount,
            'type' => $type,
            'is_reconciled' => false,
        ]);
    }
}
