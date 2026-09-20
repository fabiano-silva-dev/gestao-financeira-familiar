<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreditCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_credit_cards(): void
    {
        $this->get(route('credit-cards.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_only_lists_cards_from_current_workspace(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);

        $visibleCard = CreditCard::factory()
            ->for($currentWorkspace)
            ->create(['name' => 'Cartão visível']);
        CreditCard::factory()
            ->for($otherWorkspace)
            ->create(['name' => 'Cartão de outro workspace']);

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->get(route('credit-cards.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credit-cards/index')
                ->has('cards', 1)
                ->where('cards.0.id', $visibleCard->id)
                ->where('cards.0.name', 'Cartão visível')
            );
    }

    public function test_user_can_create_card_with_payment_defaults(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $holder = FamilyMember::factory()->for($workspace)->create();
        $account = FinancialAccount::factory()->for($workspace)->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-cards.store'), [
                'name' => 'Tumelero',
                'institution' => 'Tumelero',
                'last_four' => '0123',
                'holder_id' => $holder->id,
                'credit_limit' => '4500.90',
                'closing_day' => 5,
                'due_day' => 12,
                'payment_account_id' => $account->id,
                'invoice_payment_method' => PaymentMethod::Boleto->value,
                'payment_instructions' => 'Boleto emitido no aplicativo da loja',
            ])
            ->assertRedirect(route('credit-cards.index'))
            ->assertSessionHasNoErrors();

        $card = CreditCard::query()->sole();

        $this->assertSame($workspace->id, $card->workspace_id);
        $this->assertSame('Tumelero', $card->name);
        $this->assertSame('0123', $card->last_four);
        $this->assertSame($holder->id, $card->holder_id);
        $this->assertSame($account->id, $card->payment_account_id);
        $this->assertSame(PaymentMethod::Boleto, $card->invoice_payment_method);
        $this->assertSame('4500.90', $card->credit_limit);
        $this->assertTrue($card->is_active);
    }

    public function test_holder_and_payment_account_must_belong_to_current_workspace(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherHolder = FamilyMember::factory()->for($otherWorkspace)->create();
        $otherAccount = FinancialAccount::factory()->for($otherWorkspace)->create();

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->post(route('credit-cards.store'), [
                ...$this->validCardData(),
                'holder_id' => $otherHolder->id,
                'payment_account_id' => $otherAccount->id,
            ])
            ->assertSessionHasErrors(['holder_id', 'payment_account_id']);

        $this->assertDatabaseCount('credit_cards', 0);
    }

    public function test_card_requires_valid_cycle_limit_and_payment_method(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->post(route('credit-cards.store'), [
                ...$this->validCardData(),
                'last_four' => '12A',
                'credit_limit' => '-1',
                'closing_day' => 0,
                'due_day' => 32,
                'invoice_payment_method' => PaymentMethod::CreditCard->value,
            ])
            ->assertSessionHasErrors([
                'last_four',
                'credit_limit',
                'closing_day',
                'due_day',
                'invoice_payment_method',
            ]);

        $this->assertDatabaseCount('credit_cards', 0);
    }

    public function test_user_can_update_card_from_current_workspace(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()
            ->for($workspace)
            ->create();

        $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id])
            ->put(route('credit-cards.update', $card), [
                ...$this->validCardData(),
                'name' => 'Cartão atualizado',
                'invoice_payment_method' => PaymentMethod::Pix->value,
                'payment_instructions' => 'Chave PIX da administradora',
            ])
            ->assertRedirect(route('credit-cards.index'))
            ->assertSessionHasNoErrors();

        $card->refresh();

        $this->assertSame('Cartão atualizado', $card->name);
        $this->assertSame(PaymentMethod::Pix, $card->invoice_payment_method);
        $this->assertSame('Chave PIX da administradora', $card->payment_instructions);
    }

    public function test_card_from_another_active_workspace_cannot_be_changed(): void
    {
        [$user, $currentWorkspace] = $this->userAndWorkspace();
        $otherWorkspace = Workspace::factory()->create();
        $user->workspaces()->attach($otherWorkspace, ['role' => 'member']);
        $otherCard = CreditCard::factory()
            ->for($otherWorkspace)
            ->create(['name' => 'Nome protegido']);

        $this->actingAs($user)
            ->withSession([
                CurrentWorkspace::SESSION_KEY => $currentWorkspace->id,
            ])
            ->put(route('credit-cards.update', $otherCard), [
                ...$this->validCardData(),
                'name' => 'Tentativa indevida',
            ])
            ->assertNotFound();

        $this->assertSame('Nome protegido', $otherCard->fresh()->name);
    }

    public function test_user_can_deactivate_and_reactivate_card(): void
    {
        [$user, $workspace] = $this->userAndWorkspace();
        $card = CreditCard::factory()
            ->for($workspace)
            ->create(['is_active' => true]);

        $request = $this->actingAs($user)
            ->withSession([CurrentWorkspace::SESSION_KEY => $workspace->id]);

        $request->patch(route('credit-cards.toggle-status', $card))
            ->assertRedirect(route('credit-cards.index'));

        $this->assertFalse($card->fresh()->is_active);

        $request->patch(route('credit-cards.toggle-status', $card))
            ->assertRedirect(route('credit-cards.index'));

        $this->assertTrue($card->fresh()->is_active);
    }

    /**
     * @return array<string, mixed>
     */
    private function validCardData(): array
    {
        return [
            'name' => 'Cartão principal',
            'institution' => 'Instituição',
            'last_four' => '1234',
            'holder_id' => null,
            'credit_limit' => '2500.00',
            'closing_day' => 5,
            'due_day' => 12,
            'payment_account_id' => null,
            'invoice_payment_method' => PaymentMethod::Boleto->value,
            'payment_instructions' => null,
        ];
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
