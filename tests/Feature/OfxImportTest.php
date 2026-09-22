<?php

namespace Tests\Feature;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
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
                ->has('pdfLayouts', 2)
                ->where('pdfLayouts.0.value', 'banrisul_current_account')
                ->where('pdfLayouts.1.value', 'mercado_pago_credit_card')
            );
    }

    public function test_user_can_import_ofx_without_creating_financial_entries(): void
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
        ]);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('account_movements', 0);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/index')
                ->has('imports', 1)
                ->where('imports.0.imported_records', 2)
                ->has('entries', 2)
                ->where('pendingEntriesCount', 2)
            );
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

    /** @return array{User, Workspace} */
    private function userAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $user->workspaces()->attach($workspace, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
