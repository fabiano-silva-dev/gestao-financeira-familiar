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
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CreditCardInvoiceController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly CreditCardInvoiceService $invoiceService,
    ) {}

    public function index(): Response
    {
        $invoices = $this->workspace()
            ->creditCardInvoices()
            ->with('creditCard:id,name,last_four')
            ->orderByDesc('due_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CreditCardInvoice $invoice): array => $this->invoiceData($invoice));

        return Inertia::render('credit-card-invoices/index', [
            'invoices' => $invoices,
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
                'payments.account:id,name',
                'statementEntries',
            ]);

        $workspace = $this->workspace();
        $card = $creditCardInvoice->creditCard()->firstOrFail();

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
                    ->map(fn (CardStatementEntry $entry): array => [
                        'id' => $entry->id,
                        'purchased_on' => $entry->purchased_on->toDateString(),
                        'description' => $entry->description,
                        'amount' => $entry->amount,
                        'installment_number' => $entry->installment_number,
                        'total_installments' => $entry->total_installments,
                        'is_reconciled' => $entry->is_reconciled,
                    ])
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
}
