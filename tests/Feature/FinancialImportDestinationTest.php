<?php

namespace Tests\Feature;

use App\Enums\AccountMovementType;
use App\Enums\CategoryType;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionOrigin;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinancialImportDestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_statement_can_move_to_another_account(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $origin = FinancialAccount::factory()->for($workspace)->create(['name' => 'Conta antiga']);
        $target = FinancialAccount::factory()->for($workspace)->create(['name' => 'Conta nova']);
        $import = $this->importStatement($user, $workspace, $origin);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.reassign', $import), [
                'document_type' => 'bank_statement',
                'financial_account_id' => $target->id,
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $import->refresh();
        $this->assertSame($target->id, $import->financial_account_id);
        $this->assertSame(2, BankStatementEntry::query()->where('financial_account_id', $target->id)->count());
        $this->assertSame(0, BankStatementEntry::query()->where('financial_account_id', $origin->id)->count());
    }

    public function test_link_to_existing_movement_is_released_when_the_statement_moves(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $origin = FinancialAccount::factory()->for($workspace)->create();
        $target = FinancialAccount::factory()->for($workspace)->create();
        $import = $this->importStatement($user, $workspace, $origin);
        $entry = BankStatementEntry::query()->where('amount', '-89.90')->firstOrFail();
        $movement = AccountMovement::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => $origin->id,
            'occurred_on' => $entry->occurred_on->toDateString(),
            'description' => 'Energia já lançada',
            'amount' => '-89.90',
            'type' => AccountMovementType::ExpensePayment,
            'is_reconciled' => true,
        ]);
        $entry->update([
            'account_movement_id' => $movement->id,
            'is_reconciled' => true,
            'reconciled_at' => now(),
            'reconciled_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.reassign', $import), [
                'document_type' => 'bank_statement',
                'financial_account_id' => $target->id,
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $movement->refresh();
        $this->assertSame($target->id, $entry->financial_account_id);
        $this->assertFalse($entry->is_reconciled);
        $this->assertNull($entry->account_movement_id);
        $this->assertSame($origin->id, $movement->financial_account_id);
        $this->assertFalse($movement->is_reconciled);
    }

    public function test_completed_invoice_can_move_to_another_card(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $origin = CreditCard::factory()->for($workspace)->create([
            'name' => 'Cartão antigo',
            'last_four' => '1111',
            'closing_day' => 5,
            'due_day' => 12,
        ]);
        $target = CreditCard::factory()->for($workspace)->create([
            'name' => 'Cartão novo',
            'last_four' => '2222',
            'closing_day' => 5,
            'due_day' => 12,
        ]);
        Category::factory()->for($workspace)->create([
            'name' => 'Esporte',
            'type' => CategoryType::Expense,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.card-statements.store'), [
                'credit_card_id' => $origin->id,
                'reference_month' => '2026-10',
                'amount_sign' => 'positive',
                'file' => UploadedFile::fake()->createWithContent(
                    'fatura.csv',
                    "Data da compra;Estabelecimento;Valor (R$);Parcela;Identificador\n10/09/2026;Vôlei Lidiane;89,90;2/10;linha-001\n",
                ),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $import = FinancialImport::query()->sole();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.reassign', $import), [
                'document_type' => 'credit_card_statement',
                'credit_card_id' => $target->id,
                'reference_month' => '2026-10',
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $import->refresh();
        $this->assertSame($target->id, $import->credit_card_id);
        $this->assertSame(1, CardStatementEntry::query()->where('credit_card_id', $target->id)->count());
        $this->assertSame(0, CardStatementEntry::query()->where('credit_card_id', $origin->id)->count());
        $this->assertSame(
            0,
            FinancialTransaction::query()
                ->where('origin', FinancialTransactionOrigin::CardImport)
                ->where('credit_card_id', $origin->id)
                ->count(),
        );
    }

    public function test_changing_type_without_a_compatible_file_keeps_the_import(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()->for($workspace)->create();
        $card = CreditCard::factory()->for($workspace)->create([
            'closing_day' => 5,
            'due_day' => 12,
        ]);
        $import = $this->importStatement($user, $workspace, $account);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.reassign', $import), [
                'document_type' => 'credit_card_statement',
                'credit_card_id' => $card->id,
                'reference_month' => '2026-10',
            ])
            ->assertSessionHasErrors('document_type');

        $import->refresh();
        $this->assertSame(FinancialImportStatus::Completed, $import->status);
        $this->assertSame($account->id, $import->financial_account_id);
        $this->assertSame(1, FinancialImport::query()->count());
        $this->assertSame(2, BankStatementEntry::query()->count());
    }

    private function importStatement(
        User $user,
        Workspace $workspace,
        FinancialAccount $account,
    ): FinancialImport {
        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.ofx.store'), [
                'financial_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent('extrato.ofx', <<<'OFX'
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
                    <BANKID>000
                    <ACCTID>12345
                    <ACCTTYPE>CHECKING
                    </BANKACCTFROM>
                    <BANKTRANLIST>
                    <DTSTART>20260901000000
                    <DTEND>20260930000000
                    <STMTTRN>
                    <TRNTYPE>DEBIT
                    <DTPOSTED>20260910120000[-3:BRT]
                    <TRNAMT>-89.90
                    <FITID>fit-001
                    <NAME>Energia elétrica
                    <MEMO>Débito
                    </STMTTRN>
                    <STMTTRN>
                    <TRNTYPE>CREDIT
                    <DTPOSTED>20260920120000[-3:BRT]
                    <TRNAMT>2500.00
                    <FITID>fit-002
                    <NAME>Salário
                    <MEMO>Crédito
                    </STMTTRN>
                    </BANKTRANLIST>
                    </STMTRS>
                    </STMTTRNRS>
                    </BANKMSGSRSV1>
                    </OFX>
                    OFX),
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasNoErrors();

        $import = FinancialImport::query()->sole();
        $this->assertSame(FinancialImportType::Ofx, $import->type);

        return $import;
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
