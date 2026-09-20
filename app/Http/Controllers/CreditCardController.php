<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Http\Requests\StoreCreditCardRequest;
use App\Http\Requests\UpdateCreditCardRequest;
use App\Models\CreditCard;
use App\Models\FamilyMember;
use App\Models\FinancialAccount;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CreditCardController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function index(): Response
    {
        $cards = $this->workspace()
            ->creditCards()
            ->with(['holder:id,name', 'paymentAccount:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (CreditCard $card): array => $this->cardData($card));

        return Inertia::render('credit-cards/index', [
            'cards' => $cards,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('credit-cards/create', [
            ...$this->referenceOptions(),
            'invoicePaymentMethods' => PaymentMethod::invoiceOptions(),
        ]);
    }

    public function store(StoreCreditCardRequest $request): RedirectResponse
    {
        $this->workspace()->creditCards()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Cartão cadastrado com sucesso.',
        ]);

        return to_route('credit-cards.index');
    }

    public function edit(int $card): Response
    {
        return Inertia::render('credit-cards/edit', [
            'card' => $this->cardData($this->findCard($card)),
            ...$this->referenceOptions(),
            'invoicePaymentMethods' => PaymentMethod::invoiceOptions(),
        ]);
    }

    public function update(
        UpdateCreditCardRequest $request,
        int $card,
    ): RedirectResponse {
        $this->findCard($card)->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Cartão atualizado com sucesso.',
        ]);

        return to_route('credit-cards.index');
    }

    public function toggleStatus(int $card): RedirectResponse
    {
        $creditCard = $this->findCard($card);
        $creditCard->update([
            'is_active' => ! $creditCard->is_active,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $creditCard->is_active
                ? 'Cartão ativado com sucesso.'
                : 'Cartão desativado com sucesso.',
        ]);

        return to_route('credit-cards.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findCard(int $card): CreditCard
    {
        return $this->workspace()
            ->creditCards()
            ->with(['holder:id,name', 'paymentAccount:id,name'])
            ->findOrFail($card);
    }

    /**
     * @return array{
     *     memberOptions: array<int, array{id: int, name: string, is_active: bool}>,
     *     accountOptions: array<int, array{id: int, name: string, is_active: bool}>
     * }
     */
    private function referenceOptions(): array
    {
        $workspace = $this->workspace();

        return [
            'memberOptions' => $workspace
                ->familyMembers()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (FamilyMember $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'is_active' => $member->is_active,
                ])
                ->all(),
            'accountOptions' => $workspace
                ->financialAccounts()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (FinancialAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'is_active' => $account->is_active,
                ])
                ->all(),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     institution: string|null,
     *     last_four: string,
     *     holder_id: int|null,
     *     holder_name: string|null,
     *     credit_limit: string,
     *     closing_day: int,
     *     due_day: int,
     *     payment_account_id: int|null,
     *     payment_account_name: string|null,
     *     invoice_payment_method: string,
     *     invoice_payment_method_label: string,
     *     payment_instructions: string|null,
     *     is_active: bool
     * }
     */
    private function cardData(CreditCard $card): array
    {
        return [
            'id' => $card->id,
            'name' => $card->name,
            'institution' => $card->institution,
            'last_four' => $card->last_four,
            'holder_id' => $card->holder_id,
            'holder_name' => $card->holder?->name,
            'credit_limit' => $card->credit_limit,
            'closing_day' => $card->closing_day,
            'due_day' => $card->due_day,
            'payment_account_id' => $card->payment_account_id,
            'payment_account_name' => $card->paymentAccount?->name,
            'invoice_payment_method' => $card->invoice_payment_method->value,
            'invoice_payment_method_label' => $card->invoice_payment_method->label(),
            'payment_instructions' => $card->payment_instructions,
            'is_active' => $card->is_active,
        ];
    }
}
