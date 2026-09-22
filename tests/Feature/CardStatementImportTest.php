<?php

namespace Tests\Feature;

use App\Enums\CategoryType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionOrigin;
use App\Models\CardStatementEntry;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CardStatementImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_card_statement_imports(): void
    {
        $this->get(route('imports.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_import_statement_and_create_card_purchase_projection(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Tumelero',
            'last_four' => '4321',
            'closing_day' => 5,
            'due_day' => 12,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.card-statements.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'amount_sign' => 'positive',
                'file' => UploadedFile::fake()->createWithContent(
                    'fatura-outubro.csv',
                    $this->csvFile(),
                ),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $financialImport = FinancialImport::query()->sole();
        $summary = data_get($financialImport->metadata, 'processing_summary');
        $invoice = CreditCardInvoice::query()
            ->where('reference_month', '2026-10-01')
            ->sole();

        $this->assertSame(FinancialImportType::CardStatement, $financialImport->type);
        $this->assertSame(FinancialImportStatus::Completed, $financialImport->status);
        $this->assertSame(2, $financialImport->total_records);
        $this->assertSame(2, $financialImport->imported_records);
        $this->assertSame(0, $financialImport->duplicate_records);
        $this->assertSame('2026-09-10', $financialImport->statement_start_on?->toDateString());
        $this->assertSame('2026-09-12', $financialImport->statement_end_on?->toDateString());
        $this->assertNotNull($financialImport->stored_path);
        Storage::disk('local')->assertExists($financialImport->stored_path);

        $this->assertSame('2026-10-01', $invoice->reference_month->toDateString());
        $this->assertSame('2026-10-05', $invoice->closing_date->toDateString());
        $this->assertSame('2026-10-12', $invoice->due_date->toDateString());
        $this->assertSame('79.90', $invoice->statement_amount);
        $this->assertSame('89.90', $invoice->calculated_amount);
        $this->assertDatabaseCount('card_statement_entries', 2);
        $this->assertDatabaseHas('card_statement_entries', [
            'credit_card_id' => $card->id,
            'credit_card_invoice_id' => $invoice->id,
            'purchased_on' => '2026-09-10',
            'description' => 'Vôlei Lidiane',
            'amount' => '89.90',
            'installment_number' => 2,
            'total_installments' => 10,
            'external_id' => 'linha-001',
            'is_reconciled' => true,
        ]);
        $this->assertDatabaseHas('card_statement_entries', [
            'description' => 'Estorno mensalidade',
            'amount' => '-10.00',
        ]);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseHas('financial_transactions', [
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'description' => 'Vôlei Lidiane',
            'amount' => '899.00',
            'origin' => FinancialTransactionOrigin::CardImport->value,
        ]);
        $this->assertDatabaseCount('transaction_installments', 9);
        $this->assertDatabaseHas('transaction_installments', [
            'installment_number' => 2,
            'total_installments' => 10,
            'amount' => '89.90',
            'credit_card_invoice_id' => $invoice->id,
        ]);
        $this->assertDatabaseHas('transaction_installments', [
            'installment_number' => 10,
            'total_installments' => 10,
            'amount' => '89.90',
        ]);
        $this->assertDatabaseCount('credit_card_invoices', 9);
        $this->assertDatabaseCount('account_movements', 0);
        $this->assertSame(1, $summary['automatically_reconciled']);
        $this->assertSame(1, $summary['new_transactions_created']);
        $this->assertSame(1, $summary['pending_categorization']);
        $this->assertSame(1, $summary['pending_confirmation']);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/index')
                ->has('imports', 1)
                ->where('imports.0.statement_amount', '79.90')
                ->has('entries', 2)
                ->where('pendingEntriesCount', 1)
            );

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('credit-card-invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('invoice.statement_entries', 2)
                ->where('invoice.statement_entries.0.is_reconciled', false)
                ->where('invoice.statement_entries.1.is_reconciled', true)
            );
    }

    public function test_reimporting_same_file_is_rejected(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('imports.card-statements.store'), [
            'credit_card_id' => $card->id,
            'reference_month' => '2026-10',
            'amount_sign' => 'positive',
            'file' => UploadedFile::fake()->createWithContent(
                'fatura.csv',
                $this->csvFile(),
            ),
        ])->assertSessionHasNoErrors();

        $request->post(route('imports.card-statements.store'), [
            'credit_card_id' => $card->id,
            'reference_month' => '2026-10',
            'amount_sign' => 'positive',
            'file' => UploadedFile::fake()->createWithContent(
                'fatura-renomeada.csv',
                $this->csvFile(),
            ),
        ])->assertSessionHasErrors([
            'file' => 'Este arquivo já foi importado. Envie um arquivo diferente.',
        ])->assertInertiaFlash('toast', [
            'type' => 'warning',
            'message' => 'Este arquivo já foi importado.',
        ]);

        $this->assertDatabaseCount('financial_imports', 1);
        $this->assertDatabaseCount('card_statement_entries', 2);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('transaction_installments', 9);
    }

    public function test_overlapping_files_ignore_existing_external_ids(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('imports.card-statements.store'), [
            'credit_card_id' => $card->id,
            'reference_month' => '2026-10',
            'amount_sign' => 'positive',
            'file' => UploadedFile::fake()->createWithContent('primeira.csv', $this->csvFile()),
        ])->assertSessionHasNoErrors();

        $request->post(route('imports.card-statements.store'), [
            'credit_card_id' => $card->id,
            'reference_month' => '2026-10',
            'amount_sign' => 'positive',
            'file' => UploadedFile::fake()->createWithContent(
                'segunda.csv',
                "Data;Estabelecimento;Valor;Parcela;Identificador\n10/09/2026;Vôlei Lidiane;89,90;2/10;linha-001\n15/09/2026;Handebol Luiza;120,00;1/8;linha-003\n",
            ),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('financial_imports', 2);
        $this->assertDatabaseCount('card_statement_entries', 3);
        $secondImport = FinancialImport::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $secondImport->imported_records);
        $this->assertSame(1, $secondImport->duplicate_records);
    }

    public function test_identical_rows_without_ids_are_preserved_by_occurrence(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $csv = "Data;Descrição;Valor\n10/09/2026;Pedágio;12,50\n10/09/2026;Pedágio;12,50\n";

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.card-statements.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'amount_sign' => 'positive',
                'file' => UploadedFile::fake()->createWithContent('pedagios.csv', $csv),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('card_statement_entries', 2);
    }

    public function test_negative_purchase_convention_is_normalized(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.card-statements.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'amount_sign' => 'negative',
                'file' => UploadedFile::fake()->createWithContent(
                    'sinais.csv',
                    "Data,Descrição,Valor\n10/09/2026,Compra,-50.00\n11/09/2026,Estorno,10.00\n",
                ),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('card_statement_entries', [
            'description' => 'Compra',
            'amount' => '50.00',
        ]);
        $this->assertDatabaseHas('card_statement_entries', [
            'description' => 'Estorno',
            'amount' => '-10.00',
        ]);
        $this->assertSame('40.00', CreditCardInvoice::query()->sole()->statement_amount);
    }

    public function test_import_does_not_overwrite_amount_of_closed_invoice(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        CreditCardInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'credit_card_id' => $card->id,
            'reference_month' => '2026-10-01',
            'closing_date' => '2026-09-25',
            'due_date' => '2026-10-10',
            'calculated_amount' => '80.00',
            'statement_amount' => '80.00',
            'paid_amount' => '0.00',
            'status' => CreditCardInvoiceStatus::Closed,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.card-statements.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'amount_sign' => 'positive',
                'file' => UploadedFile::fake()->createWithContent('fatura.csv', $this->csvFile()),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('80.00', CreditCardInvoice::query()->sole()->statement_amount);
        $metadata = FinancialImport::query()->sole()->metadata;
        $this->assertFalse($metadata['statement_amount_applied']);
        $this->assertSame('79.90', $metadata['statement_amount']);
    }

    public function test_ai_enrichment_identifies_merchant_and_existing_expense_category(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $category = Category::factory()->for($workspace)->create([
            'name' => 'Mercado',
            'type' => CategoryType::Expense,
            'is_active' => true,
        ]);

        config()->set('financial_ai.enabled', true);
        config()->set('financial_ai.gemini.api_key', 'fake-gemini-key');
        config()->set('financial_ai.gemini.models', ['gemini-test']);
        config()->set('financial_ai.groq.api_key', '');

        Http::fake(function () use ($category) {
            $entryId = CardStatementEntry::query()->sole()->id;

            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'items' => [[
                                    'entry_id' => $entryId,
                                    'merchant_name' => 'Supermercado XYZ',
                                    'merchant_confidence' => 0.96,
                                    'category_id' => $category->id,
                                    'category_confidence' => 0.93,
                                ]],
                            ]),
                        ]],
                    ],
                ]],
            ]);
        });

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.card-statements.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'amount_sign' => 'positive',
                'file' => UploadedFile::fake()->createWithContent(
                    'fatura-ia.csv',
                    "Data;Estabelecimento;Valor;Identificador\n10/09/2026;SUPERMERCADO XYZ 001;50,00;ai-001\n",
                ),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('financial_transactions', [
            'workspace_id' => $workspace->id,
            'description' => 'SUPERMERCADO XYZ 001',
            'payee_name' => 'Supermercado XYZ',
            'category_id' => $category->id,
            'origin' => FinancialTransactionOrigin::CardImport->value,
        ]);

        $metadata = FinancialImport::query()->sole()->metadata;
        $this->assertSame(['gemini'], $metadata['ai_classification']['providers']);
        $this->assertSame(['gemini-test'], $metadata['ai_classification']['models']);
        $this->assertSame(1, $metadata['ai_classification']['merchant_updates']);
        $this->assertGreaterThanOrEqual(1, $metadata['ai_classification']['classified_records']);

        Http::assertSentCount(1);
    }

    public function test_nubank_invoice_creates_categorized_expenses_and_skips_payment(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
            'institution' => 'Nubank',
        ]);
        $transporte = Category::factory()->for($workspace)->create([
            'name' => 'Transporte',
            'type' => CategoryType::Expense,
            'is_active' => true,
        ]);
        $farmacia = Category::factory()->for($workspace)->create([
            'name' => 'Farmácia',
            'type' => CategoryType::Expense,
            'is_active' => true,
        ]);
        $csv = <<<'CSV'
            date,title,amount
            2026-05-22,99app *99app,"8,08"
            2026-05-10,Farmacia Sao Joao,"33,89"
            2026-05-08,Pagamento recebido,"- 2.307,13"
            CSV;

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.card-statements.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-06',
                'amount_sign' => 'negative',
                'file' => UploadedFile::fake()->createWithContent(
                    'Nubank_2026-06-11.csv',
                    $csv,
                ),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('card_statement_entries', 2);
        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseMissing('financial_transactions', [
            'description' => 'Pagamento recebido',
        ]);
        $this->assertDatabaseHas('financial_transactions', [
            'description' => '99app *99app',
            'amount' => '8.08',
            'category_id' => $transporte->id,
            'origin' => FinancialTransactionOrigin::CardImport->value,
        ]);
        $this->assertDatabaseHas('financial_transactions', [
            'description' => 'Farmacia Sao Joao',
            'amount' => '33.89',
            'category_id' => $farmacia->id,
            'origin' => FinancialTransactionOrigin::CardImport->value,
        ]);
        $this->assertTrue(
            CardStatementEntry::query()->where('description', '99app *99app')->sole()->is_reconciled,
        );
        $this->assertSame('41.97', CreditCardInvoice::query()->sole()->statement_amount);
        $this->assertSame('41.97', CreditCardInvoice::query()->sole()->calculated_amount);
    }

    public function test_user_can_import_mercado_pago_pdf_invoice(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Mercado Pago Fabiano',
            'last_four' => '3736',
            'closing_day' => 2,
            'due_day' => 8,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.store'), [
                'kind' => 'invoice',
                'credit_card_id' => $card->id,
                'reference_month' => '2026-09',
                'amount_sign' => 'auto',
                'pdf_layout' => 'mercado_pago_credit_card',
                'file' => UploadedFile::fake()->createWithContent(
                    'fatura-setembro-mercado-pago.pdf',
                    $this->mercadoPagoPdf(),
                ),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $financialImport = FinancialImport::query()->sole();
        $this->assertSame(FinancialImportType::CardStatement, $financialImport->type);
        $this->assertSame(FinancialImportStatus::Completed, $financialImport->status);
        $this->assertSame(3, $financialImport->total_records);
        $this->assertSame(3, $financialImport->imported_records);
        $this->assertSame('pdf-mercado-pago', $financialImport->metadata['source_format'] ?? null);
        $this->assertSame('mercado_pago_credit_card', $financialImport->metadata['pdf_layout'] ?? null);

        $this->assertDatabaseCount('card_statement_entries', 3);
        $this->assertDatabaseHas('card_statement_entries', [
            'description' => 'Smhigienizacoes Parcela 2 de 3',
            'amount' => '93.33',
            'installment_number' => 2,
            'total_installments' => 3,
            'purchased_on' => '2026-07-09',
        ]);
        $this->assertDatabaseHas('card_statement_entries', [
            'description' => 'VEST COMPANHIA Parcela 1 de 6',
            'amount' => '91.80',
            'installment_number' => 1,
            'total_installments' => 6,
        ]);
        $this->assertDatabaseHas('card_statement_entries', [
            'description' => 'DL*99 RIDE',
            'amount' => '8.60',
        ]);
        $this->assertDatabaseMissing('card_statement_entries', [
            'description' => 'Pagamento da fatura de agosto/2026',
        ]);
    }

    public function test_invalid_statement_is_recorded_as_failed(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.card-statements.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'amount_sign' => 'positive',
                'file' => UploadedFile::fake()->createWithContent(
                    'invalida.csv',
                    "Coluna A;Coluna B\nUm;Dois\n",
                ),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(FinancialImportStatus::Failed, FinancialImport::query()->sole()->status);
        $this->assertDatabaseCount('card_statement_entries', 0);
        $this->assertDatabaseCount('credit_card_invoices', 0);
    }

    public function test_import_cannot_reference_card_from_another_workspace(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $otherCard = CreditCard::factory()->for($otherWorkspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.card-statements.store'), [
                'credit_card_id' => $otherCard->id,
                'reference_month' => '2026-10',
                'amount_sign' => 'positive',
                'file' => UploadedFile::fake()->createWithContent('fatura.csv', $this->csvFile()),
            ])
            ->assertSessionHasErrors('credit_card_id');

        $this->assertDatabaseCount('financial_imports', 0);
        $this->assertDatabaseCount('card_statement_entries', 0);
    }

    private function csvFile(): string
    {
        return "Data da compra;Estabelecimento;Valor (R$);Parcela;Identificador\n10/09/2026;Vôlei Lidiane;89,90;2/10;linha-001\n12/09/2026;Estorno mensalidade;-10,00;;linha-002\n";
    }

    private function mercadoPagoPdf(): string
    {
        $lines = [
            'Pague sua fatura pelo app Mercado Pago',
            'Vencimento: 08/09/2026',
            'Detalhes de consumo',
            'Movimentações na fatura',
            '04/08 Pagamento da fatura de agosto/2026 R$ 1.500,00',
            '07/08 Pagamento da fatura de agosto/2026 R$ 2.108,26',
            'Cartão Visa [************3736]',
            '09/07 Smhigienizacoes Parcela 2 de 3 R$ 93,33',
            '05/08 VEST COMPANHIA Parcela 1 de 6 R$ 91,80',
            'Cartão Visa [************3759]',
            '04/08 DL*99 RIDE R$ 8,60',
            'Parcele a fatura do seu Cartão de Crédito Mercado Pago',
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
