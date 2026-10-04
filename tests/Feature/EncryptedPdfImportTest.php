<?php

namespace Tests\Feature;

use App\Enums\FinancialImportStatus;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EncryptedPdfImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_encrypted_mercado_pago_pdf_opens_with_the_first_digits_of_the_cpf(): void
    {
        $this->assertSame(
            'gestao_financeira_familiar_testing',
            config('database.connections.pgsql.database'),
        );
        Storage::fake('local');
        Http::fake();
        [$user, $workspace] = $this->userAndWorkspace();
        FamilyMember::factory()->for($workspace)->create([
            'name' => 'Fabiano',
            'cpf' => '52998224725',
        ]);
        $card = CreditCard::factory()->for($workspace)->create([
            'name' => 'Mercado Pago',
            'institution' => 'Mercado Pago',
            'last_four' => '3759',
        ]);

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('imports.store'), [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'fatura-outubro.pdf',
                        file_get_contents(base_path('tests/Fixtures/encrypted-mercado-pago-invoice.pdf')),
                    ),
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $import = FinancialImport::query()->sole();

        $this->assertSame(FinancialImportStatus::Completed, $import->status);
        $this->assertSame($card->id, $import->credit_card_id);
        $this->assertSame('12.20', $import->metadata['statement_amount'] ?? null);
        $this->assertDatabaseHas('card_statement_entries', [
            'credit_card_id' => $card->id,
            'description' => 'DL*99 RIDE',
            'amount' => '12.20',
        ]);
        $this->assertSame(1, CardStatementEntry::query()->count());
    }

    public function test_encrypted_pdf_without_cpf_returns_a_validation_error(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->from(route('imports.index'))
            ->post(route('imports.store'), [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'fatura-outubro.pdf',
                        file_get_contents(base_path('tests/Fixtures/encrypted-mercado-pago-invoice.pdf')),
                    ),
                ],
            ])
            ->assertRedirect(route('imports.index'))
            ->assertSessionHasErrors('files');

        $this->assertSame(0, FinancialImport::query()->count());
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
