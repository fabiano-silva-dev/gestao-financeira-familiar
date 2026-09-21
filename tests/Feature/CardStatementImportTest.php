<?php

namespace Tests\Feature;

use App\Enums\CategoryType;
use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionOrigin;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
        $this->get(route('imports.card-statements.index'))
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
            ->assertRedirect(route('imports.card-statements.index'))
            ->assertSessionHasNoErrors();

        $financialImport = FinancialImport::query()->sole();
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

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.card-statements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/card-statements')
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

    public function test_reimporting_same_file_is_idempotent(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()->for($workspace)->create();
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        foreach (['fatura.csv', 'fatura-renomeada.csv'] as $filename) {
            $request->post(route('imports.card-statements.store'), [
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
                'amount_sign' => 'positive',
                'file' => UploadedFile::fake()->createWithContent(
                    $filename,
                    $this->csvFile(),
                ),
            ])->assertSessionHasNoErrors();
        }

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

        Http::fake(function (Request $request) use ($category) {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'] ?? '';
            preg_match('/"entry_id":(\\d+)/', (string) $prompt, $matches);
            $entryId = (int) ($matches[1] ?? 0);

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
        $this->assertSame(1, $metadata['ai_classification']['classified_records']);
        $this->assertSame(1, $metadata['ai_classification']['merchant_updates']);
        $this->assertSame(1, $metadata['ai_classification']['category_updates']);

        Http::assertSentCount(1);
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

    /** @return array{User, Workspace} */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
