<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinancialAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_financial_accounts(): void
    {
        $this->get(route('accounts.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_only_lists_accounts_from_current_workspace(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);

        $visibleAccount = FinancialAccount::factory()
            ->for($currentWorkspace)
            ->create(['name' => 'Conta visível']);
        FinancialAccount::factory()
            ->for($otherWorkspace)
            ->create(['name' => 'Conta de outro workspace']);

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('accounts/index')
                ->has('accounts', 1)
                ->where('accounts.0.id', $visibleAccount->id)
                ->where('accounts.0.name', 'Conta visível')
            );
    }

    public function test_user_can_create_account_in_current_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('accounts.store'), [
                'name' => 'Sicredi principal',
                'institution' => 'Sicredi',
                'agency' => '1234',
                'account_number' => '98765-4',
                'type' => FinancialAccountType::Checking->value,
                'opening_balance' => '1250.45',
                'opening_balance_date' => '2026-09-01',
            ])
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHasNoErrors();

        $account = FinancialAccount::query()->sole();

        $this->assertSame($workspace->id, $account->workspace_id);
        $this->assertSame('Sicredi principal', $account->name);
        $this->assertSame('1234', $account->agency);
        $this->assertSame('98765-4', $account->account_number);
        $this->assertSame('1250.45', $account->opening_balance);
        $this->assertSame('2026-09-01', $account->opening_balance_date?->toDateString());
        $this->assertTrue($account->is_active);
    }

    public function test_account_requires_valid_type_two_decimal_places_and_opening_date(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('accounts.store'), [
                'name' => 'Conta inválida',
                'institution' => null,
                'type' => 'credit_card',
                'opening_balance' => '10.999',
                'opening_balance_date' => 'data-invalida',
            ])
            ->assertSessionHasErrors([
                'type',
                'opening_balance',
                'opening_balance_date',
            ]);

        $this->assertDatabaseCount('financial_accounts', 0);
    }

    public function test_user_can_update_an_account_from_current_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()
            ->for($workspace)
            ->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('accounts.update', $account), [
                'name' => 'Nubank pessoal',
                'institution' => 'Nubank',
                'agency' => '0001',
                'account_number' => '12345678-9',
                'type' => FinancialAccountType::Digital->value,
                'opening_balance' => '-25.50',
                'opening_balance_date' => '2026-09-10',
            ])
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHasNoErrors();

        $account->refresh();

        $this->assertSame('Nubank pessoal', $account->name);
        $this->assertSame('0001', $account->agency);
        $this->assertSame('12345678-9', $account->account_number);
        $this->assertSame(FinancialAccountType::Digital, $account->type);
        $this->assertSame('-25.50', $account->opening_balance);
        $this->assertSame('2026-09-10', $account->opening_balance_date?->toDateString());
    }

    public function test_account_from_another_active_workspace_cannot_be_changed(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherAccount = FinancialAccount::factory()
            ->for($otherWorkspace)
            ->create();

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->put(route('accounts.update', $otherAccount), [
                'name' => 'Tentativa indevida',
                'institution' => null,
                'type' => FinancialAccountType::Cash->value,
                'opening_balance' => '0',
                'opening_balance_date' => '2026-09-01',
            ])
            ->assertNotFound();

        $this->assertNotSame('Tentativa indevida', $otherAccount->fresh()->name);
    }

    public function test_user_can_deactivate_and_reactivate_an_account(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $account = FinancialAccount::factory()
            ->for($workspace)
            ->create(['is_active' => true]);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->patch(route('accounts.toggle-status', $account))
            ->assertRedirect(route('accounts.index'));

        $this->assertFalse($account->fresh()->is_active);

        $request->patch(route('accounts.toggle-status', $account))
            ->assertRedirect(route('accounts.index'));

        $this->assertTrue($account->fresh()->is_active);
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
