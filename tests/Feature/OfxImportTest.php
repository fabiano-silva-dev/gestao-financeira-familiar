<?php

namespace Tests\Feature;

use App\Enums\CategoryType;
use App\Enums\ClassificationRuleAutomationLevel;
use App\Enums\ClassificationRuleMatchType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\ClassificationRule;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OfxImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_ofx_imports(): void
    {
        $this->get(route('imports.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_open_unified_imports_page(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/index')
                ->has('pdfLayouts', 3)
                ->where('pdfLayouts.0.value', 'banrisul_current_account')
                ->where('pdfLayouts.1.value', 'mercado_pago_account_statement')
                ->where('pdfLayouts.2.value', 'mercado_pago_credit_card')
            );
    }

    public function test_user_can_import_ofx_and_auto_process_financial_entries(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Conta principal',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'extrato-setembro.ofx',
                    $this->ofxFile(),
                ),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $financialImport = FinancialImport::query()->sole();
        $summary = data_get($financialImport->metadata, 'processing_summary');

        $this->assertSame(FinancialImportType::Ofx, $financialImport->type);
        $this->assertSame(FinancialImportStatus::Completed, $financialImport->status);
        $this->assertSame(2, $financialImport->total_records);
        $this->assertSame(2, $financialImport->imported_records);
        $this->assertSame(0, $financialImport->duplicate_records);
        $this->assertSame('2026-09-01', $financialImport->statement_start_on?->toDateString());
        $this->assertSame('2026-09-30', $financialImport->statement_end_on?->toDateString());
        $this->assertSame('12345-6', $financialImport->external_account_identifier);
        $this->assertNotNull($financialImport->stored_path);
        Storage::disk('local')->assertExists($financialImport->stored_path);

        $this->assertDatabaseCount('bank_statement_entries', 2);
        $this->assertDatabaseHas('bank_statement_entries', [
            'financial_account_id' => $account->id,
            'external_id' => 'fit-001',
            'occurred_on' => '2026-09-10',
            'amount' => '-89.90',
            'description' => 'Energia elétrica',
            'is_reconciled' => false,
        ]);
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => 'fit-002',
            'amount' => '2500.00',
            'is_reconciled' => false,
        ]);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('account_movements', 0);
        $this->assertSame(0, $summary['automatically_reconciled']);
        $this->assertSame(0, $summary['new_transactions_created']);
        $this->assertSame(0, $summary['pending_categorization']);
        $this->assertSame(2, $summary['pending_confirmation']);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/index')
                ->has('imports', 1)
                ->where('imports.0.imported_records', 2)
                ->where('imports.0.processing_summary.automatically_reconciled', 0)
                ->missing('entries')
                ->where('pendingEntriesCount', 2)
            );
    }

    public function test_import_reconciles_existing_movement_before_creating_new_transaction(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $category = Category::factory()->for($workspace)->create([
            'name' => 'Moradia',
            'type' => CategoryType::Expense->value,
        ]);
        app(FinancialEntryService::class)->create($workspace, [
            'type' => FinancialTransactionType::Expense->value,
            'transaction_date' => '2026-09-10',
            'competence_date' => '2026-09-10',
            'description' => 'Energia elétrica',
            'amount' => '89.90',
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => $category->id,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Other->value,
            'payee_name' => 'RGE',
            'payment_instructions' => null,
            'due_date' => '2026-09-10',
            'settled_on' => '2026-09-10',
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ], FinancialTransactionOrigin::Manual);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'energia.ofx',
                    $this->ofxFile([[
                        'type' => 'DEBIT',
                        'date' => '20260910120000[-3:BRT]',
                        'amount' => '-89.90',
                        'fitid' => 'energia-001',
                        'name' => 'Energia elétrica',
                        'memo' => 'Débito automático',
                    ]]),
                ),
            ])
            ->assertSessionHasNoErrors();

        $import = FinancialImport::query()->sole();
        $summary = data_get($import->metadata, 'processing_summary');

        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('account_movements', 1);
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => 'energia-001',
            'is_reconciled' => true,
        ]);
        $this->assertSame(1, $summary['matched_existing']);
        $this->assertSame(0, $summary['new_transactions_created']);
    }

    public function test_import_keeps_existing_uncategorized_movement_pending(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        app(FinancialEntryService::class)->create($workspace, [
            'type' => FinancialTransactionType::Income->value,
            'transaction_date' => '2026-09-21',
            'competence_date' => '2026-09-21',
            'description' => 'Rendimentos',
            'amount' => '0.04',
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'category_id' => null,
            'family_member_id' => null,
            'payment_method' => PaymentMethod::Other->value,
            'payee_name' => 'Rendimentos',
            'payment_instructions' => null,
            'due_date' => '2026-09-21',
            'settled_on' => '2026-09-21',
            'status' => FinancialTransactionStatus::Confirmed->value,
            'notes' => null,
        ], FinancialTransactionOrigin::Manual);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'rendimentos.ofx',
                    $this->ofxFile([[
                        'type' => 'CREDIT',
                        'date' => '20260921120000[-3:BRT]',
                        'amount' => '0.04',
                        'fitid' => 'rend-001',
                        'name' => 'Rendimentos',
                        'memo' => 'Rendimento da reserva',
                    ]]),
                ),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => 'rend-001',
            'is_reconciled' => false,
        ]);
    }

    public function test_import_applies_classification_rule_when_creating_transaction(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create([
            'name' => 'Combustível',
            'type' => CategoryType::Expense->value,
        ]);
        ClassificationRule::factory()->for($workspace)->create([
            'match_type' => ClassificationRuleMatchType::Contains->value,
            'pattern' => 'POSTO IPIRANGA',
            'action_type' => FinancialTransactionType::Expense->value,
            'automation_level' => ClassificationRuleAutomationLevel::CreateAndReconcile,
            'payee_name' => 'Posto Ipiranga',
            'category_id' => $category->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'posto.ofx',
                    $this->ofxFile([[
                        'type' => 'DEBIT',
                        'date' => '20260912120000[-3:BRT]',
                        'amount' => '-185.90',
                        'fitid' => 'posto-001',
                        'name' => 'POSTO IPIRANGA',
                        'memo' => 'Compra no débito',
                    ]]),
                ),
            ])
            ->assertSessionHasNoErrors();

        $import = FinancialImport::query()->sole();
        $summary = data_get($import->metadata, 'processing_summary');

        $this->assertDatabaseHas('financial_transactions', [
            'description' => 'POSTO IPIRANGA',
            'category_id' => $category->id,
            'payee_name' => 'Posto Ipiranga',
        ]);
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => 'posto-001',
            'is_reconciled' => true,
            'automation_level_applied' => ClassificationRuleAutomationLevel::CreateAndReconcile->value,
            'automation_result' => 'created_and_reconciled',
        ]);
        $this->assertSame(1, $summary['categorized_automatically']);
        $this->assertSame(0, $summary['pending_categorization']);
    }

    public function test_rule_can_only_classify_and_leave_imported_entry_pending(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create([
            'name' => 'Mercado',
            'type' => CategoryType::Expense->value,
        ]);
        $rule = ClassificationRule::factory()->for($workspace)->create([
            'match_type' => ClassificationRuleMatchType::Contains,
            'pattern' => 'SUPERMERCADO TESTE',
            'action_type' => FinancialTransactionType::Expense,
            'automation_level' => ClassificationRuleAutomationLevel::ClassifyOnly,
            'payee_name' => 'Supermercado Teste',
            'category_id' => $category->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'mercado.ofx',
                    $this->ofxFile([[
                        'type' => 'DEBIT',
                        'date' => '20260913120000[-3:BRT]',
                        'amount' => '-50.00',
                        'fitid' => 'mercado-001',
                        'name' => 'SUPERMERCADO TESTE',
                        'memo' => 'Compra',
                    ]]),
                ),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => 'mercado-001',
            'is_reconciled' => false,
            'suggested_payee_name' => 'Supermercado Teste',
            'suggested_category_id' => $category->id,
            'matched_classification_rule_id' => $rule->id,
            'automation_level_applied' => ClassificationRuleAutomationLevel::ClassifyOnly->value,
            'automation_result' => 'classified_pending',
        ]);
    }

    public function test_reconcile_existing_rule_never_creates_a_new_transaction(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create([
            'name' => 'Serviços',
            'type' => CategoryType::Expense->value,
        ]);
        $rule = ClassificationRule::factory()->for($workspace)->create([
            'match_type' => ClassificationRuleMatchType::Contains,
            'pattern' => 'SERVICO SEM LANCAMENTO',
            'action_type' => FinancialTransactionType::Expense,
            'automation_level' => ClassificationRuleAutomationLevel::ReconcileExisting,
            'payee_name' => 'Serviço Teste',
            'category_id' => $category->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'servico.ofx',
                    $this->ofxFile([[
                        'type' => 'DEBIT',
                        'date' => '20260914120000[-3:BRT]',
                        'amount' => '-75.00',
                        'fitid' => 'servico-001',
                        'name' => 'SERVICO SEM LANCAMENTO',
                        'memo' => 'Pagamento',
                    ]]),
                ),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => 'servico-001',
            'is_reconciled' => false,
            'matched_classification_rule_id' => $rule->id,
            'automation_level_applied' => ClassificationRuleAutomationLevel::ReconcileExisting->value,
            'automation_result' => 'pending_no_existing_match',
        ]);
    }

    public function test_import_identifies_invoice_payment_without_creating_expense(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank',
            'institution' => 'Nubank',
            'payment_account_id' => $account->id,
            'invoice_payment_method' => PaymentMethod::Boleto->value,
        ]);
        $invoice = CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => '2026-09-01',
            'closing_date' => '2026-09-08',
            'due_date' => '2026-09-15',
            'calculated_amount' => '3250.00',
            'statement_amount' => '3250.00',
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Open->value,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'pagamento-fatura.ofx',
                    $this->ofxFile([[
                        'type' => 'DEBIT',
                        'date' => '20260915120000[-3:BRT]',
                        'amount' => '-3250.00',
                        'fitid' => 'fatura-001',
                        'name' => 'PAGAMENTO FATURA NUBANK',
                        'memo' => 'Pagamento cartão de crédito',
                    ]]),
                ),
            ])
            ->assertSessionHasNoErrors();

        $import = FinancialImport::query()->sole();
        $summary = data_get($import->metadata, 'processing_summary');

        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('credit_card_invoice_payments', 1);
        $this->assertDatabaseCount('account_movements', 1);
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => 'fatura-001',
            'is_reconciled' => true,
        ]);
        $this->assertSame(CreditCardInvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(1, $summary['invoice_payments_identified']);
        $this->assertSame(0, $summary['new_transactions_created']);
    }

    public function test_import_links_both_sides_of_transfer_between_workspace_accounts(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $source = FinancialAccount::factory()->for($workspace)->create(['name' => 'Nubank']);
        $destination = FinancialAccount::factory()->for($workspace)->create(['name' => 'Mercado Pago']);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('imports.ofx.store'), [
            'financial_account_id' => $source->id,
            'file' => UploadedFile::fake()->createWithContent(
                'saida.ofx',
                $this->ofxFile([[
                    'type' => 'DEBIT',
                    'date' => '20260918120000[-3:BRT]',
                    'amount' => '-1000.00',
                    'fitid' => 'transf-out-001',
                    'name' => 'TRANSFERENCIA ENVIADA',
                    'memo' => 'Transferência entre contas',
                ]]),
            ),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('financial_transactions', 0);

        $request->post(route('imports.ofx.store'), [
            'financial_account_id' => $destination->id,
            'file' => UploadedFile::fake()->createWithContent(
                'entrada.ofx',
                $this->ofxFile([[
                    'type' => 'CREDIT',
                    'date' => '20260918120000[-3:BRT]',
                    'amount' => '1000.00',
                    'fitid' => 'transf-in-001',
                    'name' => 'TRANSFERENCIA RECEBIDA',
                    'memo' => 'Transferência entre contas',
                ]]),
            ),
        ])->assertSessionHasNoErrors();

        $latestImport = FinancialImport::query()->latest('id')->firstOrFail();
        $summary = data_get($latestImport->metadata, 'processing_summary');

        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseHas('financial_transactions', [
            'type' => FinancialTransactionType::Transfer->value,
            'amount' => '1000.00',
        ]);
        $this->assertDatabaseCount('account_movements', 2);
        $this->assertSame(2, $workspace->bankStatementEntries()->where('is_reconciled', true)->count());
        $this->assertSame(1, $summary['transfers_identified']);
        $this->assertSame(0, $summary['pending_confirmation']);
    }

    public function test_reimporting_same_file_is_rejected(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('imports.ofx.store'), [
            'financial_account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent(
                'primeiro.ofx',
                $this->ofxFile(),
            ),
        ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $request->post(route('imports.ofx.store'), [
            'financial_account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent(
                'renomeado.ofx',
                $this->ofxFile(),
            ),
        ])
            ->assertSessionHasErrors([
                'file' => 'Este arquivo já foi importado. Envie um arquivo diferente.',
            ])
            ->assertInertiaFlash('toast', [
                'type' => 'warning',
                'message' => 'Este arquivo já foi importado.',
            ]);

        $this->assertDatabaseCount('financial_imports', 1);
        $this->assertDatabaseCount('bank_statement_entries', 2);
        $this->assertSame(2, FinancialImport::query()->sole()->imported_records);
    }

    public function test_overlapping_files_ignore_existing_fitids(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('imports.ofx.store'), [
            'financial_account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent(
                'setembro.ofx',
                $this->ofxFile(),
            ),
        ])->assertSessionHasNoErrors();

        $request->post(route('imports.ofx.store'), [
            'financial_account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent(
                'outubro.ofx',
                $this->ofxFile([
                    [
                        'type' => 'CREDIT',
                        'date' => '20260920120000[-3:BRT]',
                        'amount' => '2500.00',
                        'fitid' => 'fit-002',
                        'name' => 'Salário',
                        'memo' => 'Mesmo movimento em janela sobreposta',
                    ],
                    [
                        'type' => 'DEBIT',
                        'date' => '20261001120000[-3:BRT]',
                        'amount' => '-50.00',
                        'fitid' => 'fit-003',
                        'name' => 'Internet',
                        'memo' => 'Pagamento mensal',
                    ],
                ]),
            ),
        ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('financial_imports', 2);
        $this->assertDatabaseCount('bank_statement_entries', 3);
        $secondImport = FinancialImport::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $secondImport->imported_records);
        $this->assertSame(1, $secondImport->duplicate_records);
    }

    public function test_movements_without_fitid_use_deterministic_fallback(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);
        $transactions = [[
            'type' => 'DEBIT',
            'date' => '20260915120000[-3:BRT]',
            'amount' => '-35.90',
            'name' => 'Farmácia',
            'memo' => 'Compra no débito',
        ]];
        $firstFile = $this->ofxFile($transactions);
        $overlappingFile = str_replace('VERSION:102', 'VERSION:103', $firstFile);

        foreach ([
            'primeira-janela.ofx' => $firstFile,
            'segunda-janela.ofx' => $overlappingFile,
        ] as $filename => $contents) {
            $request->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    $filename,
                    $contents,
                ),
            ])->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('financial_imports', 2);
        $this->assertDatabaseCount('bank_statement_entries', 1);
        $secondImport = FinancialImport::query()->latest('id')->firstOrFail();
        $this->assertSame(0, $secondImport->imported_records);
        $this->assertSame(1, $secondImport->duplicate_records);
    }

    public function test_user_can_import_csv_bank_statement(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'extrato.csv',
                    "Data;Histórico;Valor\n10/09/2026;PIX Enviado;-50,00\n11/09/2026;Salário;2500,00\n",
                ),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('bank_statement_entries', 2);
        $this->assertDatabaseHas('bank_statement_entries', [
            'description' => 'PIX Enviado',
            'amount' => '-50.00',
            'is_reconciled' => false,
        ]);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_user_can_import_mercado_pago_csv_statement(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'account_statement-mercado-pago.csv',
                    <<<'CSV'
                    INITIAL_BALANCE;CREDITS;DEBITS;FINAL_BALANCE
                    1,52;25.441,34;-25.442,86;0,00

                    RELEASE_DATE;TRANSACTION_TYPE;REFERENCE_ID;TRANSACTION_NET_AMOUNT;PARTIAL_BALANCE
                    01-07-2026;Dinheiro reservado Despesas Mensais ;166611940878;-1,52;0,00
                    02-07-2026;Pix recebido FABIANO CARVALHO DA SILVA;166801941210;500,00;500,00
                    02-07-2026;Pagamento Cartão de crédito;166802160620;-500,00;0,00
                    CSV,
                ),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $financialImport = FinancialImport::query()->sole();
        $this->assertSame(3, $financialImport->total_records);
        $this->assertSame(3, $financialImport->imported_records);
        $this->assertSame('2026-07-01', $financialImport->statement_start_on?->toDateString());
        $this->assertSame('2026-07-02', $financialImport->statement_end_on?->toDateString());
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => '166611940878',
            'description' => 'Dinheiro reservado Despesas Mensais',
            'amount' => '-1.52',
            'is_reconciled' => false,
        ]);
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => '166801941210',
            'amount' => '500.00',
            'is_reconciled' => false,
        ]);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_user_can_import_banrisul_pdf_statement(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'pdf_layout' => 'banrisul_current_account',
                'file' => UploadedFile::fake()->createWithContent(
                    'extrato-banrisul.pdf',
                    $this->banrisulPdf(),
                ),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('bank_statement_entries', 2);
        $this->assertDatabaseHas('bank_statement_entries', [
            'description' => 'RESGATE CDB',
            'amount' => '10000.00',
        ]);
        $this->assertDatabaseHas('bank_statement_entries', [
            'description' => 'PIX ENVIADO - VOLMIR JAQUES CECHIN',
            'amount' => '-354.00',
        ]);
    }

    public function test_user_can_import_mercado_pago_pdf_statement(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Mercado Pago Lidiane',
            'institution' => 'Mercado Pago',
            'account_number' => '59404058338',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'pdf_layout' => 'mercado_pago_account_statement',
                'file' => UploadedFile::fake()->createWithContent(
                    'extrato-mercado-pago.pdf',
                    $this->mercadoPagoAccountPdf(),
                ),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $financialImport = FinancialImport::query()->sole();

        $this->assertSame(FinancialImportStatus::Completed, $financialImport->status);
        $this->assertSame(2, $financialImport->total_records);
        $this->assertSame('59404058338', $financialImport->external_account_identifier);
        $this->assertSame('2026-06-01', $financialImport->statement_start_on?->toDateString());
        $this->assertSame('2026-06-30', $financialImport->statement_end_on?->toDateString());
        $this->assertSame(
            'mercado_pago_account_statement',
            data_get($financialImport->metadata, 'pdf_layout'),
        );
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => '162800502640',
            'description' => 'Reembolso de compra Mercado Libre',
            'amount' => '39.80',
        ]);
        $this->assertDatabaseHas('bank_statement_entries', [
            'external_id' => '1745432447364',
            'description' => 'Rendimentos',
            'amount' => '0.03',
        ]);
    }

    public function test_header_only_bank_csv_is_recorded_as_no_movement(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Nubank Lidiane',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'NU_8407288736_01JUL2026_31JUL2026.csv',
                    "Data,Valor,Identificador,Descrição\n",
                ),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $financialImport = FinancialImport::query()->sole();

        $this->assertSame(FinancialImportStatus::NoMovement, $financialImport->status);
        $this->assertNull($financialImport->error_message);
        $this->assertSame(0, $financialImport->total_records);
        $this->assertSame('2026-07-01', $financialImport->statement_start_on?->toDateString());
        $this->assertSame('2026-07-31', $financialImport->statement_end_on?->toDateString());
        $this->assertDatabaseCount('bank_statement_entries', 0);
    }

    public function test_invalid_ofx_is_recorded_as_failed(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'invalido.ofx',
                    'conteúdo sem estrutura bancária',
                ),
            ])
            ->assertSessionHasErrors('file');

        $financialImport = FinancialImport::query()->sole();
        $this->assertSame(FinancialImportStatus::Failed, $financialImport->status);
        $this->assertNotNull($financialImport->error_message);
        $this->assertDatabaseCount('bank_statement_entries', 0);
    }

    public function test_import_cannot_reference_account_from_another_workspace(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $otherAccount->id,
                'file' => UploadedFile::fake()->createWithContent(
                    'extrato.ofx',
                    $this->ofxFile(),
                ),
            ])
            ->assertSessionHasErrors('financial_account_id');

        $this->assertDatabaseCount('financial_imports', 0);
        $this->assertDatabaseCount('bank_statement_entries', 0);
    }

    /**
     * @param  array<int, array{type: string, date: string, amount: string, fitid?: string, name: string, memo: string}>|null  $transactions
     */
    private function ofxFile(?array $transactions = null): string
    {
        $transactions ??= [
            [
                'type' => 'DEBIT',
                'date' => '20260910120000[-3:BRT]',
                'amount' => '-89.90',
                'fitid' => 'fit-001',
                'name' => 'Energia elétrica',
                'memo' => 'Pagamento via débito automático',
            ],
            [
                'type' => 'CREDIT',
                'date' => '20260920120000[-3:BRT]',
                'amount' => '2500.00',
                'fitid' => 'fit-002',
                'name' => 'Salário',
                'memo' => 'Crédito em conta',
            ],
        ];
        $entries = '';

        foreach ($transactions as $transaction) {
            $fitid = isset($transaction['fitid'])
                ? "<FITID>{$transaction['fitid']}\n"
                : '';
            $entries .= <<<OFX
                <STMTTRN>
                <TRNTYPE>{$transaction['type']}
                <DTPOSTED>{$transaction['date']}
                <TRNAMT>{$transaction['amount']}
                {$fitid}
                <NAME>{$transaction['name']}
                <MEMO>{$transaction['memo']}
                </STMTTRN>
                OFX;
        }

        return <<<OFX
            OFXHEADER:100
            DATA:OFXSGML
            VERSION:102
            SECURITY:NONE
            ENCODING:UTF-8
            CHARSET:UTF-8

            <OFX>
            <BANKMSGSRSV1>
            <STMTTRNRS>
            <STMTRS>
            <CURDEF>BRL
            <BANKACCTFROM>
            <BANKID>748
            <ACCTID>12345-6
            </BANKACCTFROM>
            <BANKTRANLIST>
            <DTSTART>20260901000000[-3:BRT]
            <DTEND>20260930235959[-3:BRT]
            {$entries}
            </BANKTRANLIST>
            </STMTRS>
            </STMTTRNRS>
            </BANKMSGSRSV1>
            </OFX>
            OFX;
    }

    private function banrisulPdf(): string
    {
        $lines = [
            'BANRISUL 04/12/2025',
            'CONTA..: 06.006855.0-9',
            'MOVIMENTOS DA CONTA CORRENTE',
            '++   MOVIMENTOS NOV/2025',
            '03   RESGATE CDB                             000006   10.000,00',
            '     PIX ENVIADO                             065918      354,00-',
            '      NOME: VOLMIR JAQUES CECHIN',
        ];
        $stream = "BT /F1 9 Tf\n";
        $y = 750;

        foreach ($lines as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $stream .= sprintf("1 0 0 1 24 %d Tm (%s) Tj\n", $y, $escaped);
            $y -= 14;
        }

        $stream .= 'ET';
        $length = strlen($stream);

        return <<<PDF
        %PDF-1.4
        1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
        2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj
        3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj
        4 0 obj<</Length {$length}>>stream
        {$stream}
        endstream
        endobj
        5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Courier>>endobj
        trailer<</Root 1 0 R>>
        %%EOF
        PDF;
    }

    private function mercadoPagoAccountPdf(): string
    {
        $lines = [
            'Mercado Pago',
            'EXTRATO DE CONTA',
            'Lidiane Ribeiro Ferro da Silva',
            'CPF/CNPJ: 97775975091 Agencia: 1 Conta: 59404058338',
            'Periodo: De 01-06-2026 al 30-06-2026',
            'DETALHE DOS MOVIMENTOS',
            'Data Descricao ID da operacao Valor Saldo',
            'Reembolso de compra',
            '17-06-2026 Mercado Libre 162800502640 R$ 39,80 R$ 39,80',
            '18-06-2026 Rendimentos 1745432447364 R$ 0,03 R$ 39,83',
        ];
        $stream = "BT /F1 9 Tf\n";
        $y = 750;

        foreach ($lines as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $stream .= sprintf("1 0 0 1 24 %d Tm (%s) Tj\n", $y, $escaped);
            $y -= 14;
        }

        $stream .= 'ET';
        $length = strlen($stream);

        return <<<PDF
        %PDF-1.4
        1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
        2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj
        3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj
        4 0 obj<</Length {$length}>>stream
        {$stream}
        endstream
        endobj
        5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Courier>>endobj
        trailer<</Root 1 0 R>>
        %%EOF
        PDF;
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
