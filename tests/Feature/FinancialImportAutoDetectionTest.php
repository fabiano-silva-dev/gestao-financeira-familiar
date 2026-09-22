<?php

namespace Tests\Feature;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\ImportSourceBinding;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinancialImportAutoDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ofx_is_detected_and_processed_without_prior_choices(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Sicredi',
            'institution' => 'Sicredi',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.store'), [
                'files' => [
                    UploadedFile::fake()->createWithContent('extrato.ofx', $this->ofx()),
                ],
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $import = FinancialImport::query()->sole();

        $this->assertSame(FinancialImportType::Ofx, $import->type);
        $this->assertSame(FinancialImportStatus::Completed, $import->status);
        $this->assertSame($account->id, $import->financial_account_id);
        $this->assertSame('sicredi', data_get($import->metadata, 'autodetection.institution'));
        $this->assertSame('bank_statement', data_get($import->metadata, 'autodetection.document_type'));
        $this->assertFalse((bool) data_get($import->metadata, 'autodetection.confirmed_by_user'));
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseHas('bank_statement_entries', [
            'financial_account_id' => $account->id,
            'is_reconciled' => true,
        ]);
    }

    public function test_multiple_files_are_detected_and_processed_in_one_upload(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Sicredi',
            'institution' => 'Sicredi',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.store'), [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'extrato-1.ofx',
                        $this->ofx('auto-batch-001', '-89.90', '20260910120000[-3:BRT]'),
                    ),
                    UploadedFile::fake()->createWithContent(
                        'extrato-2.ofx',
                        $this->ofx('auto-batch-002', '-49.90', '20260911120000[-3:BRT]'),
                    ),
                ],
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('financial_imports', 2);
        $this->assertDatabaseCount('bank_statement_entries', 2);
        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertSame(
            2,
            FinancialImport::query()
                ->where('status', FinancialImportStatus::Completed->value)
                ->count(),
        );
    }

    public function test_ambiguous_nubank_csv_keeps_uncategorized_purchase_pending_after_card_confirmation(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $first = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
            'institution' => 'Nubank',
            'last_four' => '1111',
        ]);
        CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank Lidiane',
            'institution' => 'Nubank',
            'last_four' => '2222',
        ]);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('imports.store'), [
            'files' => [
                UploadedFile::fake()->createWithContent(
                    'Nubank_2026-09-11.csv',
                    "date,title,amount\n2026-09-02,Posto Ipiranga,\"185,90\"\n",
                ),
            ],
        ])->assertSessionHasNoErrors();

        $pending = FinancialImport::query()->sole();

        $this->assertSame(FinancialImportType::Document, $pending->type);
        $this->assertSame(FinancialImportStatus::NeedsConfirmation, $pending->status);
        $this->assertSame('nubank', data_get($pending->metadata, 'autodetection.institution'));
        $this->assertSame('credit_card_statement', data_get($pending->metadata, 'autodetection.document_type'));
        $this->assertSame('2026-09', data_get($pending->metadata, 'autodetection.reference_month'));
        $this->assertContains('credit_card_id', data_get($pending->metadata, 'missing_fields'));
        $this->assertDatabaseCount('financial_transactions', 0);

        $request->post(route('imports.resolve', $pending), [
            'document_type' => 'credit_card_statement',
            'credit_card_id' => $first->id,
            'reference_month' => '2026-09',
        ])->assertSessionHasNoErrors();

        $final = FinancialImport::query()->sole();
        $this->assertSame(FinancialImportType::CardStatement, $final->type);
        $this->assertSame($first->id, $final->credit_card_id);
        $this->assertTrue((bool) data_get($final->metadata, 'autodetection.confirmed_by_user'));
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertFalse(CardStatementEntry::query()->sole()->is_reconciled);
    }

    public function test_mercado_pago_pdf_detects_card_holder_name(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.store'), [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'fatura-mercado-pago.pdf',
                        $this->mercadoPagoDetectionPdf(),
                    ),
                ],
            ])
            ->assertSessionHasNoErrors();

        $pending = FinancialImport::query()->sole();

        $this->assertSame(FinancialImportStatus::NeedsConfirmation, $pending->status);
        $this->assertSame('mercado_pago', data_get($pending->metadata, 'autodetection.institution'));
        $this->assertSame('credit_card_statement', data_get($pending->metadata, 'autodetection.document_type'));
        $this->assertSame('3759', data_get($pending->metadata, 'autodetection.identifier_value'));
        $this->assertSame('2026-08', data_get($pending->metadata, 'autodetection.reference_month'));
        $this->assertSame(
            'Fabiano Carvalho da Silva',
            data_get($pending->metadata, 'autodetection.holder_name'),
        );
    }

    public function test_import_confirmation_options_include_account_and_card_identification_details(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $holder = FamilyMember::factory()->for($workspace)->create([
            'name' => 'Fabiano',
        ]);
        $paymentAccount = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
            'institution' => 'Nubank',
            'agency' => '0001',
            'account_number' => '12345678-9',
        ]);
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Nubank Fabiano',
            'institution' => 'Nubank',
            'last_four' => '4321',
            'holder_id' => $holder->id,
            'payment_account_id' => $paymentAccount->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->get(route('imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accountOptions.0.id', $paymentAccount->id)
                ->where('accountOptions.0.agency', '0001')
                ->where('accountOptions.0.account_number', '12345678-9')
                ->where('cardOptions.0.id', $card->id)
                ->where('cardOptions.0.holder_name', 'Fabiano')
                ->where('cardOptions.0.payment_account_name', 'Nubank Fabiano')
                ->where('cardOptions.0.payment_account_agency', '0001')
                ->where('cardOptions.0.payment_account_number', '12345678-9')
            );
    }

    public function test_account_number_resolves_ambiguous_banrisul_accounts_without_confirmation(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $matching = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Banrisul principal',
            'institution' => 'Banrisul',
            'agency' => '060',
            'account_number' => '06.006855.0-9',
        ]);
        FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Banrisul secundária',
            'institution' => 'Banrisul',
            'agency' => '060',
            'account_number' => '99.999999.9-9',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.store'), [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'banrisul-identificado.pdf',
                        $this->banrisulPdf('03', '354,00-'),
                    ),
                ],
            ])
            ->assertSessionHasNoErrors();

        $import = FinancialImport::query()->sole();

        $this->assertSame(FinancialImportStatus::Completed, $import->status);
        $this->assertSame($matching->id, $import->financial_account_id);
        $this->assertDatabaseCount('import_source_bindings', 1);
    }

    public function test_confirmed_banrisul_account_identifier_is_learned_for_next_import(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $first = FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Banrisul principal',
            'institution' => 'Banrisul',
        ]);
        FinancialAccount::factory()->for($workspace)->create([
            'name' => 'Banrisul secundária',
            'institution' => 'Banrisul',
        ]);
        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->post(route('imports.store'), [
            'files' => [
                UploadedFile::fake()->createWithContent(
                    'banrisul-novembro.pdf',
                    $this->banrisulPdf('03', '354,00-'),
                ),
            ],
        ])->assertSessionHasNoErrors();

        $pending = FinancialImport::query()->sole();
        $this->assertSame(FinancialImportStatus::NeedsConfirmation, $pending->status);
        $this->assertSame('0600685509', data_get($pending->metadata, 'autodetection.identifier_value'));

        $request->post(route('imports.resolve', $pending), [
            'document_type' => 'bank_statement',
            'financial_account_id' => $first->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('import_source_bindings', 1);
        $binding = ImportSourceBinding::query()->sole();
        $this->assertSame($first->id, $binding->financial_account_id);
        $this->assertSame('0600685509', $binding->identifier_value);

        $request->post(route('imports.store'), [
            'files' => [
                UploadedFile::fake()->createWithContent(
                    'banrisul-novembro-2.pdf',
                    $this->banrisulPdf('04', '120,00-'),
                ),
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, FinancialImport::query()
            ->where('status', FinancialImportStatus::NeedsConfirmation->value)
            ->count());
        $this->assertSame(2, FinancialImport::query()
            ->where('type', FinancialImportType::Ofx->value)
            ->where('financial_account_id', $first->id)
            ->count());
    }

    public function test_unknown_proof_is_preserved_without_creating_financial_data(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.store'), [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'comprovante.pdf',
                        $this->simplePdf([
                            'NUBANK',
                            'COMPROVANTE DE PIX',
                            'Transferência realizada com sucesso',
                        ]),
                    ),
                ],
            ])
            ->assertSessionHasNoErrors();

        $pending = FinancialImport::query()->sole();

        $this->assertSame(FinancialImportStatus::NeedsConfirmation, $pending->status);
        $this->assertSame('proof', data_get($pending->metadata, 'autodetection.document_type'));
        $this->assertSame('nubank', data_get($pending->metadata, 'autodetection.institution'));
        $this->assertNotNull($pending->stored_path);
        Storage::disk('local')->assertExists($pending->stored_path);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('bank_statement_entries', 0);
        $this->assertDatabaseCount('card_statement_entries', 0);
    }

    private function ofx(
        string $fitid = 'auto-001',
        string $amount = '-89.90',
        string $date = '20260910120000[-3:BRT]',
    ): string {
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
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>{$date}
<TRNAMT>{$amount}
<FITID>{$fitid}
<NAME>Energia elétrica
<MEMO>Débito automático
</STMTTRN>
</BANKTRANLIST>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;
    }

    private function mercadoPagoDetectionPdf(): string
    {
        return $this->simplePdf([
            'Fabiano Carvalho da Silva',
            'Emitida em: 03/08/2026',
            'Olá, Fabiano',
            'Essa é sua fatura de agosto',
            'Vence em 07/08/2026',
            'Pague sua fatura pelo app Mercado Pago',
            'Detalhes de consumo',
            'Movimentações na fatura',
            'Cartão Visa [************3759]',
            '30/06 DL*GOOGLE Google R$ 14,99',
        ]);
    }

    private function banrisulPdf(string $day, string $amount): string
    {
        return $this->simplePdf([
            'BANRISUL 04/12/2025',
            'CONTA..: 06.006855.0-9',
            'MOVIMENTOS DA CONTA CORRENTE',
            '++   MOVIMENTOS NOV/2025',
            "{$day}   PIX ENVIADO                             065918      {$amount}",
            '      NOME: TESTE DESTINATARIO',
        ]);
    }

    /** @param list<string> $lines */
    private function simplePdf(array $lines): string
    {
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
