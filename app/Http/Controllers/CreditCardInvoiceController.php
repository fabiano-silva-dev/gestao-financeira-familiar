<?php

namespace App\Http\Controllers;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\PaymentMethod;
use App\Http\Requests\CloseCreditCardInvoiceRequest;
use App\Http\Requests\StoreCreditCardInvoicePaymentRequest;
use App\Models\CardStatementEntry;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardInvoicePayment;
use App\Models\FinancialAccount;
use App\Models\TransactionInstallment;
use App\Models\Workspace;
use App\Services\Finance\CreditCardInvoiceService;
use App\Services\Reconciliation\CardStatementReconciliationSuggestionService;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class CreditCardInvoiceController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly CreditCardInvoiceService $invoiceService,
        private readonly CardStatementReconciliationSuggestionService $reconciliationSuggestionService,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $listing = ListingQuery::from(
            $request,
            ['card', 'month', 'due_date', 'amount', 'status'],
            'due_date',
            'desc',
            ['status', 'card'],
        );
        $query = $workspace
            ->creditCardInvoices()
            ->with('creditCard:id,name,last_four')
            ->select('credit_card_invoices.*')
            ->leftJoin(
                'credit_cards',
                'credit_cards.id',
                '=',
                'credit_card_invoices.credit_card_id',
            );

        if ($listing->search !== '') {
            $term = $listing->searchTerm();
            $query->where(function ($inner) use ($term): void {
                $inner->where('credit_cards.name', 'ilike', $term)
                    ->orWhere('credit_cards.last_four', 'ilike', $term);
            });
        }

        $status = $listing->filter('status');

        if ($status === 'overdue') {
            $query->where('credit_card_invoices.status', '!=', CreditCardInvoiceStatus::Paid->value)
                ->whereDate('credit_card_invoices.due_date', '<', CarbonImmutable::today());
        } elseif ($status !== null && CreditCardInvoiceStatus::tryFrom($status) !== null) {
            $query->where('credit_card_invoices.status', $status);
        }

        $cardId = $listing->intFilter('card');

        if ($cardId !== null) {
            $query->where('credit_card_invoices.credit_card_id', $cardId);
        }

        $listing->applySort($query, [
            'card' => 'credit_cards.name',
            'month' => 'credit_card_invoices.reference_month',
            'due_date' => 'credit_card_invoices.due_date',
            'amount' => 'credit_card_invoices.calculated_amount',
            'status' => 'credit_card_invoices.status',
        ], 'credit_card_invoices.id');

        return Inertia::render('credit-card-invoices/index', [
            'invoices' => $query
                ->get()
                ->map(fn (CreditCardInvoice $invoice): array => $this->invoiceData($invoice)),
            'filters' => $listing->toArray(),
            'hasRecords' => $workspace->creditCardInvoices()->exists(),
            'statusOptions' => CreditCardInvoiceStatus::filterOptions(),
            'cardOptions' => $workspace->creditCards()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'last_four'])
                ->map(fn ($card): array => [
                    'value' => (string) $card->id,
                    'label' => "{$card->name} · final {$card->last_four}",
                ]),
        ]);
    }

    public function show(int $invoice): Response
    {
        $creditCardInvoice = $this->findInvoice($invoice)
            ->load([
                'creditCard:id,name,last_four,payment_account_id,invoice_payment_method,payment_instructions',
                'installments.transaction:id,description,transaction_date,category_id,family_member_id',
                'installments.transaction.category:id,name,parent_id',
                'installments.transaction.category.parent:id,name',
                'installments.transaction.familyMember:id,name',
                'installments.cardStatementEntry:id,transaction_installment_id',
                'payments.account:id,name',
                'statementEntries.transactionInstallment.transaction:id,description,transaction_date',
                'statementEntries.reconciler:id,name',
            ]);

        $workspace = $this->workspace();
        $card = $creditCardInvoice->creditCard()->firstOrFail();
        $availableInstallments = $creditCardInvoice->installments
            ->filter(fn (TransactionInstallment $installment): bool => $installment->cardStatementEntry === null)
            ->values();

        return Inertia::render('credit-card-invoices/show', [
            'invoice' => [
                ...$this->invoiceData($creditCardInvoice),
                'payment_instructions' => $card->payment_instructions,
                'installments' => $creditCardInvoice->installments
                    ->sortBy('id')
                    ->values()
                    ->map(fn (TransactionInstallment $installment): array => $this->installmentData($installment))
                    ->all(),
                'payments' => $creditCardInvoice->payments
                    ->sortByDesc('paid_on')
                    ->values()
                    ->map(fn (CreditCardInvoicePayment $payment): array => [
                        'id' => $payment->id,
                        'paid_on' => $payment->paid_on->toDateString(),
                        'amount' => $payment->amount,
                        'payment_method' => $payment->payment_method->value,
                        'payment_method_label' => $payment->payment_method->label(),
                        'account_name' => $payment->account->name,
                        'notes' => $payment->notes,
                    ])
                    ->all(),
                'statement_entries' => $creditCardInvoice->statementEntries
                    ->sortByDesc('purchased_on')
                    ->values()
                    ->map(fn (CardStatementEntry $entry): array => $this->statementEntryData(
                        $entry,
                        $availableInstallments,
                    ))
                    ->all(),
            ],
            'accountOptions' => $workspace->financialAccounts()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (FinancialAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'is_active' => $account->is_active,
                ])
                ->all(),
            'unlinkedPayments' => $card->payments()
                ->whereNull('credit_card_invoice_id')
                ->with('account:id,name')
                ->orderByDesc('paid_on')
                ->orderByDesc('id')
                ->get()
                ->map(fn (CreditCardInvoicePayment $payment): array => [
                    'id' => $payment->id,
                    'paid_on' => $payment->paid_on->toDateString(),
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method->value,
                    'payment_method_label' => $payment->payment_method->label(),
                    'account_name' => $payment->account->name,
                    'notes' => $payment->notes,
                ])
                ->all(),
            'paymentMethods' => PaymentMethod::invoiceOptions(),
            'defaultPaymentAccountId' => $card->payment_account_id,
            'defaultPaymentMethod' => $card->invoice_payment_method->value,
            'defaultPaymentDate' => now()->toDateString(),
        ]);
    }

    public function close(
        CloseCreditCardInvoiceRequest $request,
        int $invoice,
    ): RedirectResponse {
        $this->invoiceService->close(
            $this->findInvoice($invoice),
            $request->validated('statement_amount'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Fatura fechada com sucesso.',
        ]);

        return to_route('credit-card-invoices.show', $invoice);
    }

    public function pay(
        StoreCreditCardInvoicePaymentRequest $request,
        int $invoice,
    ): RedirectResponse {
        $this->invoiceService->pay(
            $this->findInvoice($invoice),
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pagamento da fatura registrado sem criar uma nova despesa.',
        ]);

        return to_route('credit-card-invoices.show', $invoice);
    }

    public function linkPayment(
        Request $request,
        int $invoice,
        int $payment,
    ): RedirectResponse {
        $creditCardInvoice = $this->findInvoice($invoice);
        $cardPayment = $this->workspace()
            ->creditCardInvoicePayments()
            ->findOrFail($payment);

        $this->invoiceService->linkPendingPayment(
            $creditCardInvoice,
            $cardPayment,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pagamento vinculado à fatura sem criar uma nova despesa.',
        ]);

        return to_route('credit-card-invoices.show', $invoice);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findInvoice(int $invoice): CreditCardInvoice
    {
        return $this->workspace()
            ->creditCardInvoices()
            ->with('creditCard:id,name,last_four')
            ->findOrFail($invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceData(CreditCardInvoice $invoice): array
    {
        $isOverdue = $invoice->status !== CreditCardInvoiceStatus::Paid
            && $invoice->due_date->isBefore(CarbonImmutable::today());

        return [
            'id' => $invoice->id,
            'credit_card_id' => $invoice->credit_card_id,
            'credit_card_name' => $invoice->creditCard->name,
            'credit_card_last_four' => $invoice->creditCard->last_four,
            'reference_month' => $invoice->reference_month->toDateString(),
            'closing_date' => $invoice->closing_date->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'calculated_amount' => $invoice->calculated_amount,
            'statement_amount' => $invoice->statement_amount,
            'paid_amount' => $invoice->paid_amount,
            'outstanding_amount' => $this->invoiceService->outstandingAmount($invoice),
            'paid_at' => $invoice->paid_at?->toDateString(),
            'status' => $isOverdue ? 'overdue' : $invoice->status->value,
            'status_label' => $isOverdue ? 'Vencida' : $invoice->status->label(),
            'can_close' => $invoice->status === CreditCardInvoiceStatus::Open,
            'can_pay' => $invoice->status !== CreditCardInvoiceStatus::Open
                && $invoice->status !== CreditCardInvoiceStatus::Paid
                && $this->invoiceService->outstandingAmount($invoice) !== '0.00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function installmentData(TransactionInstallment $installment): array
    {
        $transaction = $installment->transaction;
        $category = $transaction->category;
        $categoryName = $category?->parent !== null
            ? "{$category->parent->name} / {$category->name}"
            : $category?->name;

        return [
            'id' => $installment->id,
            'transaction_id' => $transaction->id,
            'description' => $transaction->description,
            'transaction_date' => $transaction->transaction_date->toDateString(),
            'installment_number' => $installment->installment_number,
            'total_installments' => $installment->total_installments,
            'amount' => $installment->amount,
            'competence_month' => $installment->competence_month->toDateString(),
            'due_date' => $installment->due_date->toDateString(),
            'status' => $installment->status->value,
            'status_label' => $installment->status->label(),
            'category_name' => $categoryName,
            'family_member_name' => $transaction->familyMember?->name,
        ];
    }

    /**
     * @param  Collection<int, TransactionInstallment>  $availableInstallments
     * @return array<string, mixed>
     */
    private function statementEntryData(
        CardStatementEntry $entry,
        Collection $availableInstallments,
    ): array {
        $linkedInstallment = $entry->transactionInstallment;
        $linkedTransaction = $linkedInstallment?->transaction;

        return [
            'id' => $entry->id,
            'purchased_on' => $entry->purchased_on->toDateString(),
            'description' => $entry->description,
            'amount' => $entry->amount,
            'installment_number' => $entry->installment_number,
            'total_installments' => $entry->total_installments,
            'is_reconciled' => $entry->is_reconciled,
            'reconciled_by_name' => $entry->reconciler?->name,
            'reconciled_at' => $entry->reconciled_at?->toIso8601String(),
            'linked_installment' => $linkedInstallment === null || $linkedTransaction === null
                ? null
                : [
                    'id' => $linkedInstallment->id,
                    'transaction_id' => $linkedTransaction->id,
                    'description' => $linkedTransaction->description,
                    'transaction_date' => $linkedTransaction->transaction_date->toDateString(),
                    'installment_number' => $linkedInstallment->installment_number,
                    'total_installments' => $linkedInstallment->total_installments,
                ],
            'candidates' => $entry->is_reconciled
                ? []
                : $this->reconciliationSuggestionService->candidates(
                    $entry,
                    $availableInstallments,
                ),
        ];
    }
}
