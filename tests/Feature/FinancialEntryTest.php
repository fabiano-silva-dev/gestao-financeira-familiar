<?php

namespace Tests\Feature;

use App\Enums\AccountMovementType;
use App\Enums\CategoryType;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Services\Finance\TransferService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinancialEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_financial_entries(): void
    {
        $this->get(route('transactions.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_lists_income_expense_and_transfer_from_current_workspace(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $currentAccount = FinancialAccount::factory()->for($currentWorkspace)->create();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();

        $expense = $this->createEntry(
            $currentWorkspace,
            $currentAccount,
            FinancialTransactionType::Expense,
            ['description' => 'Despesa visível'],
        );
        $this->createEntry(
            $currentWorkspace,
            $currentAccount,
            FinancialTransactionType::Income,
            ['description' => 'Receita visível'],
        );
        $this->createEntry(
            $otherWorkspace,
            $otherAccount,
            FinancialTransactionType::Expense,
            ['description' => 'Despesa de outro workspace'],
        );
        $transferDestination = FinancialAccount::factory()
            ->for($currentWorkspace)
            ->create();
        app(TransferService::class)->create($currentWorkspace, [
            'transaction_date' => '2026-09-20',
            'description' => 'Transferência visível',
            'amount' => '50.00',
            'source_account_id' => $currentAccount->id,
            'destination_account_id' => $transferDestination->id,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('transactions/index')
                ->has('entries', 3)
                ->where('entries.2.id', $expense->id)
            );
    }

    public function test_index_filters_by_type_and_sorts_by_description(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($currentWorkspace)->create();
        $expense = $this->createEntry(
            $currentWorkspace,
            $account,
            FinancialTransactionType::Expense,
            ['description' => 'Zebra mercado'],
        );
        $this->createEntry(
            $currentWorkspace,
            $account,
            FinancialTransactionType::Income,
            ['description' => 'Salário'],
        );

        $request = $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ]);

        $request->get(route('transactions.index', ['type' => 'expense']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 1)
                ->where('entries.0.id', $expense->id)
            );

        $request->get(route('transactions.index', [
            'sort' => 'description',
            'direction' => 'asc',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 2)
                ->where('entries.0.description', 'Salário')
                ->where('entries.1.description', 'Zebra mercado')
            );
    }

    public function test_index_filters_by_parent_category_including_children(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $parent = Category::factory()->for($workspace)->create([
            'name' => 'Alimentação',
        ]);
        $child = Category::factory()->for($workspace)->create([
            'name' => 'Restaurante',
            'parent_id' => $parent->id,
        ]);
        $other = Category::factory()->for($workspace)->create([
            'name' => 'Transporte',
        ]);
        $parentEntry = $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            [
                'description' => 'Mercado',
                'category_id' => $parent->id,
            ],
        );
        $childEntry = $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            [
                'description' => 'Jantar',
                'category_id' => $child->id,
            ],
        );
        $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            [
                'description' => 'Ônibus',
                'category_id' => $other->id,
            ],
        );

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $workspace->id,
            ])
            ->get(route('transactions.index', ['category' => $parent->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('transactions/index')
                ->has('entries', 2)
                ->where('filters.category', (string) $parent->id)
                ->where('entries.0.id', $childEntry->id)
                ->where('entries.1.id', $parentEntry->id)
            );
    }

    public function test_index_filters_uncategorized_entries(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create();
        $uncategorized = $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            ['description' => 'Sem classificação'],
        );
        $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            [
                'description' => 'Com categoria',
                'category_id' => $category->id,
            ],
        );

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $workspace->id,
            ])
            ->get(route('transactions.index', ['category' => 'none']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('transactions/index')
                ->has('entries', 1)
                ->where('filters.category', 'none')
                ->where('entries.0.id', $uncategorized->id)
            );
    }

    public function test_index_filters_period_by_transaction_competence_or_installment(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $inPeriod = $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            [
                'description' => 'Competência de setembro',
                'transaction_date' => '2026-08-20',
                'competence_date' => '2026-09-05',
            ],
        );
        $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            [
                'description' => 'Fora do período',
                'transaction_date' => '2026-07-10',
                'competence_date' => '2026-07-10',
            ],
        );

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $workspace->id,
            ])
            ->get(route('transactions.index', [
                'from' => '2026-09-01',
                'to' => '2026-09-30',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('transactions/index')
                ->has('entries', 1)
                ->where('entries.0.id', $inPeriod->id)
            );
    }

    public function test_user_can_create_confirmed_pix_expense_with_payment_instructions(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $category = Category::factory()->for($workspace)->create();
        $member = FamilyMember::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                ...$this->validEntryData(
                    FinancialTransactionType::Expense,
                    $account,
                ),
                'description' => 'Vôlei e handebol',
                'amount' => '125.50',
                'category_id' => $category->id,
                'family_member_id' => $member->id,
                'payee_name' => 'Escola de esportes',
                'payment_instructions' => 'PIX para nome@provedor.com',
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $expense = FinancialTransaction::query()->sole();

        $this->assertSame(FinancialTransactionType::Expense, $expense->type);
        $this->assertSame(PaymentMethod::Pix, $expense->payment_method);
        $this->assertSame('Escola de esportes', $expense->payee_name);
        $this->assertSame('PIX para nome@provedor.com', $expense->payment_instructions);
        $this->assertSame($category->id, $expense->category_id);
        $this->assertSame($member->id, $expense->family_member_id);

        $movement = $expense->accountMovements()->sole();
        $this->assertSame(AccountMovementType::ExpensePayment, $movement->type);
        $this->assertSame('-125.50', $movement->amount);
        $this->assertCurrentBalance($user, $workspace, '874.50');
    }

    public function test_planned_expense_only_changes_balance_after_settlement(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $expense = $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            [
                'amount' => '100.00',
                'status' => FinancialTransactionStatus::Planned->value,
                'due_date' => '2026-10-10',
            ],
        );

        $this->assertCurrentBalance($user, $workspace, '1000.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('transactions.advance-status', $expense))
            ->assertRedirect(route('transactions.index'));

        $expense->refresh();

        $this->assertSame(
            FinancialTransactionStatus::Confirmed,
            $expense->status,
        );
        $this->assertNull($expense->settled_on);
        $this->assertCurrentBalance($user, $workspace, '1000.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('transactions.toggle-settlement', $expense))
            ->assertRedirect(route('transactions.index'));

        $this->assertNotNull($expense->fresh()->settled_on);
        $this->assertCurrentBalance($user, $workspace, '900.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->patch(route('transactions.toggle-settlement', $expense))
            ->assertRedirect(route('transactions.index'));

        $this->assertNull($expense->fresh()->settled_on);
        $this->assertCurrentBalance($user, $workspace, '1000.00');
    }

    public function test_confirmed_unpaid_expense_keeps_cash_unchanged_until_payment(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                ...$this->validEntryData(
                    FinancialTransactionType::Expense,
                    $account,
                ),
                'due_date' => '2026-09-25',
                'settled_on' => null,
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $expense = FinancialTransaction::query()->sole();

        $this->assertSame(
            FinancialTransactionStatus::Confirmed,
            $expense->status,
        );
        $this->assertNull($expense->settled_on);
        $this->assertDatabaseCount('account_movements', 0);
        $this->assertCurrentBalance($user, $workspace, '1000.00');
    }

    public function test_credit_card_expense_does_not_create_account_movement(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                ...$this->validEntryData(FinancialTransactionType::Expense),
                'payment_method' => PaymentMethod::CreditCard->value,
                'credit_card_id' => $card->id,
                'financial_account_id' => null,
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $expense = FinancialTransaction::query()->sole();

        $this->assertSame($card->id, $expense->credit_card_id);
        $this->assertDatabaseCount('account_movements', 0);
    }

    public function test_user_can_create_income_that_increases_account_balance(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('transactions.store'), [
                ...$this->validEntryData(
                    FinancialTransactionType::Income,
                    $account,
                ),
                'amount' => '500.00',
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $income = FinancialTransaction::query()->sole();
        $movement = $income->accountMovements()->sole();

        $this->assertSame(FinancialTransactionType::Income, $income->type);
        $this->assertSame(AccountMovementType::IncomeReceipt, $movement->type);
        $this->assertSame('500.00', $movement->amount);
        $this->assertCurrentBalance($user, $workspace, '1500.00');
    }

    public function test_entry_validates_future_payment_and_workspace_references(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();
        $otherCard = CreditCard::factory()->for($otherWorkspace)->create();
        $otherCategory = Category::factory()->for($otherWorkspace)->create();
        $otherMember = FamilyMember::factory()->for($otherWorkspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('transactions.store'), [
            ...$this->validEntryData(FinancialTransactionType::Expense),
            'status' => FinancialTransactionStatus::Planned->value,
            'due_date' => null,
            'financial_account_id' => $otherAccount->id,
            'category_id' => $otherCategory->id,
            'family_member_id' => $otherMember->id,
        ])->assertSessionHasErrors([
            'due_date',
            'financial_account_id',
            'category_id',
            'family_member_id',
        ]);

        $request->post(route('transactions.store'), [
            ...$this->validEntryData(FinancialTransactionType::Expense),
            'payment_method' => PaymentMethod::CreditCard->value,
            'financial_account_id' => null,
            'credit_card_id' => $otherCard->id,
        ])->assertSessionHasErrors('credit_card_id');

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_updating_entry_updates_its_account_movement(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $oldAccount = FinancialAccount::factory()->for($workspace)->create();
        $newAccount = FinancialAccount::factory()->for($workspace)->create();
        $expense = $this->createEntry(
            $workspace,
            $oldAccount,
            FinancialTransactionType::Expense,
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('transactions.update', $expense), [
                ...$this->validEntryData(
                    FinancialTransactionType::Expense,
                    $newAccount,
                ),
                'description' => 'Despesa atualizada',
                'amount' => '245.80',
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $movement = $expense->accountMovements()->sole();

        $this->assertSame('Despesa atualizada', $expense->description);
        $this->assertSame('245.80', $expense->amount);
        $this->assertSame($newAccount->id, $movement->financial_account_id);
        $this->assertSame('-245.80', $movement->amount);
    }

    public function test_edit_form_allows_switching_entry_type(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $expenseCategory = Category::factory()->for($workspace)->create([
            'name' => 'Mercado',
            'type' => CategoryType::Expense,
        ]);
        $incomeCategory = Category::factory()->for($workspace)->create([
            'name' => 'Salário',
            'type' => CategoryType::Income,
        ]);
        $expense = $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            ['category_id' => $expenseCategory->id],
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('transactions.edit', $expense))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('transactions/edit')
                ->has('typeOptions', 3)
                ->where('typeOptions.0.value', 'income')
                ->where('typeOptions.1.value', 'expense')
                ->where('typeOptions.2.value', 'transfer')
                ->has('categoryOptions', 2)
                ->where('categoryOptions.0.name', 'Mercado')
                ->where('categoryOptions.0.type', 'expense')
                ->where('categoryOptions.1.name', 'Salário')
                ->where('categoryOptions.1.type', 'income')
                ->where('entry.origin', 'manual')
                ->where('entry.origin_label', 'Lançamento manual')
                ->where('entry.origin_source.kind', 'manual')
                ->where('entry.origin_source.filename', null)
            );
    }

    public function test_edit_form_shows_bank_statement_origin(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
        ]);
        $expense = app(FinancialEntryService::class)->create(
            $workspace,
            $this->validEntryData(FinancialTransactionType::Expense, $account),
            FinancialTransactionOrigin::Ofx,
        );
        $movement = $expense->accountMovements()->sole();
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $account->id,
            'type' => FinancialImportType::Ofx,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => 'extrato-junho.ofx',
            'file_hash' => hash('sha256', 'origin-bank-file'),
            'deduplication_key' => hash('sha256', 'origin-bank-import'),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'imported_at' => now(),
        ]);

        BankStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'financial_account_id' => $account->id,
            'account_movement_id' => $movement->id,
            'external_id' => 'fit-origin',
            'deduplication_key' => hash('sha256', 'origin-bank-entry'),
            'occurred_on' => '2026-09-20',
            'amount' => '-100.00',
            'transaction_type' => 'DEBIT',
            'description' => 'Despesa de teste',
            'is_reconciled' => true,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('transactions.edit', $expense))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.origin', 'ofx')
                ->where('entry.origin_label', 'Extrato bancário')
                ->where('entry.origin_source.kind', 'bank_statement')
                ->where('entry.origin_source.filename', 'extrato-junho.ofx')
                ->where('entry.origin_source.target_name', 'Conta principal')
                ->where(
                    'entry.origin_source.summary',
                    'Arquivo extrato-junho.ofx · Conta principal',
                )
            );
    }

    public function test_edit_form_shows_card_statement_origin(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank',
            'last_four' => '1234',
        ]);
        $expense = app(FinancialEntryService::class)->create(
            $workspace,
            [
                ...$this->validEntryData(FinancialTransactionType::Expense),
                'payment_method' => PaymentMethod::CreditCard->value,
                'credit_card_id' => $card->id,
                'financial_account_id' => null,
            ],
            FinancialTransactionOrigin::CardImport,
        );
        $installment = $expense->installments()->with('invoice')->firstOrFail();
        $import = FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'type' => FinancialImportType::CardStatement,
            'status' => FinancialImportStatus::Completed,
            'source_filename' => 'fatura-setembro.pdf',
            'file_hash' => hash('sha256', 'origin-card-file'),
            'deduplication_key' => hash('sha256', 'origin-card-import'),
            'total_records' => 1,
            'imported_records' => 1,
            'duplicate_records' => 0,
            'imported_at' => now(),
        ]);

        CardStatementEntry::query()->create([
            'workspace_id' => $workspace->id,
            'financial_import_id' => $import->id,
            'credit_card_id' => $card->id,
            'credit_card_invoice_id' => $installment->credit_card_invoice_id,
            'transaction_installment_id' => $installment->id,
            'purchased_on' => '2026-09-20',
            'description' => 'Despesa de teste',
            'amount' => '100.00',
            'deduplication_key' => hash('sha256', 'origin-card-entry'),
            'is_reconciled' => true,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('transactions.edit', $expense))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.origin', 'card_import')
                ->where('entry.origin_label', 'Fatura de cartão')
                ->where('entry.origin_source.kind', 'card_statement')
                ->where('entry.origin_source.filename', 'fatura-setembro.pdf')
                ->where('entry.origin_source.target_name', 'Nubank · final 1234')
                ->where(
                    'entry.origin_source.summary',
                    'Arquivo fatura-setembro.pdf · Nubank · final 1234 · Competência '.$installment->invoice->reference_month->format('m/Y'),
                )
            );
    }

    public function test_user_can_convert_expense_into_income(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'opening_balance' => '1000.00',
        ]);
        $incomeCategory = Category::factory()->for($workspace)->create([
            'type' => CategoryType::Income,
        ]);
        $expense = $this->createEntry(
            $workspace,
            $account,
            FinancialTransactionType::Expense,
            ['amount' => '80.00'],
        );

        $this->assertCurrentBalance($user, $workspace, '920.00');

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('transactions.update', $expense), [
                ...$this->validEntryData(
                    FinancialTransactionType::Income,
                    $account,
                ),
                'description' => 'PIX recebido',
                'amount' => '80.00',
                'category_id' => $incomeCategory->id,
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $movement = $expense->accountMovements()->sole();

        $this->assertSame(FinancialTransactionType::Income, $expense->type);
        $this->assertSame($incomeCategory->id, $expense->category_id);
        $this->assertNull($expense->source_account_id);
        $this->assertNull($expense->destination_account_id);
        $this->assertSame(AccountMovementType::IncomeReceipt, $movement->type);
        $this->assertSame('80.00', $movement->amount);
        $this->assertCurrentBalance($user, $workspace, '1080.00');
    }

    public function test_user_can_convert_income_into_transfer(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'A Origem',
            'opening_balance' => '1000.00',
        ]);
        $destination = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'B Destino',
            'opening_balance' => '200.00',
        ]);
        $income = $this->createEntry(
            $workspace,
            $source,
            FinancialTransactionType::Income,
            ['amount' => '150.00'],
        );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('transfers.update', $income), [
                'transaction_date' => '2026-09-20',
                'description' => 'Movimentação entre contas',
                'amount' => '150.00',
                'source_account_id' => $source->id,
                'destination_account_id' => $destination->id,
                'status' => FinancialTransactionStatus::Confirmed->value,
                'notes' => null,
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $income->refresh()->load('accountMovements');

        $this->assertSame(FinancialTransactionType::Transfer, $income->type);
        $this->assertSame($source->id, $income->source_account_id);
        $this->assertSame($destination->id, $income->destination_account_id);
        $this->assertNull($income->financial_account_id);
        $this->assertNull($income->payment_method);
        $this->assertCount(2, $income->accountMovements);
        $this->assertTrue(
            $income->accountMovements->contains(
                fn ($movement): bool => $movement->type === AccountMovementType::TransferOut,
            ),
        );
        $this->assertTrue(
            $income->accountMovements->contains(
                fn ($movement): bool => $movement->type === AccountMovementType::TransferIn,
            ),
        );
        $this->assertFalse(
            $income->accountMovements->contains(
                fn ($movement): bool => $movement->type === AccountMovementType::IncomeReceipt,
            ),
        );
        $this->assertAccountBalances($user, $workspace, '850.00', '350.00');
    }

    public function test_user_can_convert_transfer_into_expense(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'A Origem',
            'opening_balance' => '1000.00',
        ]);
        $destination = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'B Destino',
            'opening_balance' => '200.00',
        ]);
        $transfer = app(TransferService::class)->create($workspace, [
            'transaction_date' => '2026-09-20',
            'description' => 'Transferência visível',
            'amount' => '120.00',
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('transactions.update', $transfer), [
                ...$this->validEntryData(
                    FinancialTransactionType::Expense,
                    $source,
                ),
                'description' => 'Pagamento reclassificado',
                'amount' => '120.00',
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors();

        $transfer->refresh()->load('accountMovements');
        $movement = $transfer->accountMovements->sole();

        $this->assertSame(FinancialTransactionType::Expense, $transfer->type);
        $this->assertSame($source->id, $transfer->financial_account_id);
        $this->assertNull($transfer->source_account_id);
        $this->assertNull($transfer->destination_account_id);
        $this->assertSame(AccountMovementType::ExpensePayment, $movement->type);
        $this->assertSame('-120.00', $movement->amount);
        $this->assertAccountBalances($user, $workspace, '880.00', '200.00');
    }

    public function test_entry_from_another_active_workspace_cannot_be_changed(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $currentAccount = FinancialAccount::factory()->for($currentWorkspace)->create();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();
        $otherEntry = $this->createEntry(
            $otherWorkspace,
            $otherAccount,
            FinancialTransactionType::Expense,
            ['description' => 'Descrição protegida'],
        );

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->put(route('transactions.update', $otherEntry), [
                ...$this->validEntryData(
                    FinancialTransactionType::Expense,
                    $currentAccount,
                ),
                'description' => 'Tentativa indevida',
            ])
            ->assertNotFound();

        $this->assertSame(
            'Descrição protegida',
            $otherEntry->fresh()->description,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEntry(
        Workspace $workspace,
        FinancialAccount $account,
        FinancialTransactionType $type,
        array $overrides = [],
    ): FinancialTransaction {
        return app(FinancialEntryService::class)->create($workspace, [
            ...$this->validEntryData($type, $account),
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validEntryData(
        FinancialTransactionType $type,
        ?FinancialAccount $account = null,
    ): array {
        return [
            'type' => $type->value,
            'transaction_date' => '2026-09-20',
            'description' => $type === FinancialTransactionType::Expense
                ? 'Despesa de teste'
                : 'Receita de teste',
            'amount' => '100.00',
            'financial_account_id' => $account?->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Pix->value,
            'payee_name' => null,
            'payment_instructions' => null,
            'due_date' => null,
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ];
    }

    private function assertCurrentBalance(
        User $user,
        Workspace $workspace,
        string $expected,
    ): void {
        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.current_balance', $expected)
            );
    }

    private function assertAccountBalances(
        User $user,
        Workspace $workspace,
        string $sourceBalance,
        string $destinationBalance,
    ): void {
        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.name', 'A Origem')
                ->where('accounts.0.current_balance', $sourceBalance)
                ->where('accounts.1.name', 'B Destino')
                ->where('accounts.1.current_balance', $destinationBalance)
            );
    }

    /**
     * @return array{User, Workspace}
     */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
