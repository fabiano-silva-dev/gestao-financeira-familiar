<?php

namespace App\Http\Controllers;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\TransactionInstallmentStatus;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardInvoicePayment;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\Workspace;
use App\Services\Finance\FinancialRecurrenceService;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentDashboardController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly FinancialRecurrenceService $recurrenceService,
    ) {}

    public function __invoke(Request $request): Response
    {
        $workspace = $this->workspace();
        $today = CarbonImmutable::today();
        $this->recurrenceService->generateForWorkspace($workspace);
        $start = $this->periodStart($request, $today);
        $end = $start->endOfMonth();

        $payable = $this->sortItems([
            ...$this->pendingTransactions($workspace, FinancialTransactionType::Expense, $start, $end, $today),
            ...$this->pendingInstallments($workspace, $start, $end, $today),
            ...$this->pendingInvoices($workspace, $start, $end, $today),
        ]);
        $paid = $this->sortItems([
            ...$this->settledTransactions($workspace, FinancialTransactionType::Expense, $start, $end),
            ...$this->settledInstallments($workspace, $start, $end),
            ...$this->cardPayments($workspace, $start, $end),
        ]);
        $receivable = $this->sortItems(
            $this->pendingTransactions($workspace, FinancialTransactionType::Income, $start, $end, $today),
        );
        $received = $this->sortItems(
            $this->settledTransactions($workspace, FinancialTransactionType::Income, $start, $end),
        );

        $paidTotal = $this->itemsTotal($paid);
        $payableTotal = $this->itemsTotal($payable);
        $receivedTotal = $this->itemsTotal($received);
        $receivableTotal = $this->itemsTotal($receivable);

        return Inertia::render('payments-dashboard', [
            'currentPeriod' => $start->toDateString(),
            'metrics' => [
                'paid' => $this->money($paidTotal),
                'payable' => $this->money($payableTotal),
                'received' => $this->money($receivedTotal),
                'receivable' => $this->money($receivableTotal),
                'projected_balance' => $this->money(
                    ($receivedTotal + $receivableTotal) - ($paidTotal + $payableTotal),
                ),
            ],
            'payable' => $payable,
            'paid' => $paid,
            'receivable' => $receivable,
            'received' => $received,
            'upcoming' => $this->upcomingGroups($payable, $today),
        ]);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    /** @return array<int, array<string, mixed>> */
    private function pendingTransactions(
        Workspace $workspace,
        FinancialTransactionType $type,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $today,
    ): array {
        return $workspace->financialTransactions()
            ->where('type', $type->value)
            ->whereIn('status', [
                FinancialTransactionStatus::Planned->value,
                FinancialTransactionStatus::Confirmed->value,
            ])
            ->whereNull('settled_on')
            ->whereNull('credit_card_id')
            ->whereDoesntHave('installments')
            ->where(function ($query) use ($start, $end): void {
                $query
                    ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function ($fallback) use ($start, $end): void {
                        $fallback
                            ->whereNull('due_date')
                            ->whereBetween('transaction_date', [
                                $start->toDateString(),
                                $end->toDateString(),
                            ]);
                    });
            })
            ->with(['account:id,name', 'recurrence:id,description'])
            ->get()
            ->map(function (FinancialTransaction $entry) use ($today, $type): array {
                $date = $entry->due_date ?? $entry->transaction_date;
                $overdue = $date->lt($today);
                $pendingLabel = $type === FinancialTransactionType::Expense
                    ? 'A pagar'
                    : 'A receber';

                return $this->item(
                    id: 'transaction-'.$entry->id,
                    source: 'transaction',
                    description: $entry->description,
                    amount: (string) $entry->amount,
                    date: $date->toDateString(),
                    statusLabel: $overdue ? 'Vencido' : $pendingLabel,
                    isOverdue: $overdue,
                    context: $entry->account?->name,
                    details: $this->transactionDetails($entry),
                );
            })
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function settledTransactions(
        Workspace $workspace,
        FinancialTransactionType $type,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $transferAccountColumn = $type === FinancialTransactionType::Expense
            ? 'transfers.source_account_id'
            : 'transfers.destination_account_id';

        return $workspace->financialTransactions()
            ->where('type', $type->value)
            ->where('status', FinancialTransactionStatus::Confirmed->value)
            ->whereNull('credit_card_id')
            ->whereNotNull('settled_on')
            ->whereDoesntHave('installments')
            ->whereBetween('settled_on', [$start->toDateString(), $end->toDateString()])
            ->whereNotExists(function ($query) use ($transferAccountColumn): void {
                $query->selectRaw('1')
                    ->from('financial_transactions as transfers')
                    ->whereColumn('transfers.workspace_id', 'financial_transactions.workspace_id')
                    ->where('transfers.type', FinancialTransactionType::Transfer->value)
                    ->where('transfers.status', '!=', FinancialTransactionStatus::Cancelled->value)
                    ->whereColumn('transfers.amount', 'financial_transactions.amount')
                    ->whereColumn('transfers.description', 'financial_transactions.description')
                    ->whereColumn('transfers.transaction_date', 'financial_transactions.settled_on')
                    ->whereColumn($transferAccountColumn, 'financial_transactions.financial_account_id');
            })
            ->with(['account:id,name', 'recurrence:id,description'])
            ->get()
            ->map(fn (FinancialTransaction $entry): array => $this->item(
                id: 'transaction-'.$entry->id,
                source: 'transaction',
                description: $entry->description,
                amount: (string) $entry->amount,
                date: $entry->settled_on->toDateString(),
                statusLabel: $type === FinancialTransactionType::Expense ? 'Pago' : 'Recebido',
                context: $entry->account?->name,
                details: $this->transactionDetails($entry),
            ))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function pendingInstallments(
        Workspace $workspace,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $today,
    ): array {
        return TransactionInstallment::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('credit_card_invoice_id')
            ->where('status', TransactionInstallmentStatus::Open->value)
            ->where(function ($query) use ($start, $end): void {
                $query
                    ->whereBetween('expected_payment_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function ($fallback) use ($start, $end): void {
                        $fallback
                            ->whereNull('expected_payment_date')
                            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()]);
                    });
            })
            ->whereHas('transaction', fn ($query) => $query
                ->where('type', FinancialTransactionType::Expense->value)
                ->whereNull('credit_card_id')
                ->where('status', '!=', FinancialTransactionStatus::Cancelled->value))
            ->with('transaction.account:id,name')
            ->get()
            ->map(function (TransactionInstallment $installment) use ($today): array {
                $date = $installment->expected_payment_date ?? $installment->due_date;
                $overdue = $date->lt($today);

                return $this->item(
                    id: 'installment-'.$installment->id,
                    source: 'installment',
                    description: $installment->transaction->description,
                    amount: (string) $installment->amount,
                    date: $date->toDateString(),
                    statusLabel: $overdue ? 'Vencido' : 'A pagar',
                    isOverdue: $overdue,
                    context: 'Parcela '.$installment->installment_number.'/'.$installment->total_installments,
                    details: $this->installmentDetails($installment),
                );
            })
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function settledInstallments(
        Workspace $workspace,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        return TransactionInstallment::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('credit_card_invoice_id')
            ->where('status', TransactionInstallmentStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
            ->whereHas('transaction', fn ($query) => $query
                ->where('type', FinancialTransactionType::Expense->value)
                ->whereNull('credit_card_id')
                ->where('status', '!=', FinancialTransactionStatus::Cancelled->value))
            ->with('transaction.account:id,name')
            ->get()
            ->map(fn (TransactionInstallment $installment): array => $this->item(
                id: 'installment-'.$installment->id,
                source: 'installment',
                description: $installment->transaction->description,
                amount: (string) $installment->amount,
                date: $installment->paid_at->toDateString(),
                statusLabel: 'Pago',
                context: 'Parcela '.$installment->installment_number.'/'.$installment->total_installments,
                details: $this->installmentDetails($installment),
            ))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function pendingInvoices(
        Workspace $workspace,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $today,
    ): array {
        return $workspace->creditCardInvoices()
            ->where('status', '!=', CreditCardInvoiceStatus::Paid->value)
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->with([
                'creditCard:id,name,last_four,payment_account_id,invoice_payment_method,payment_instructions',
                'creditCard.paymentAccount:id,name',
                'installments' => fn ($query) => $query
                    ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
                    ->orderBy('installment_number')
                    ->orderBy('id'),
                'installments.transaction:id,description,transaction_date',
            ])
            ->get()
            ->map(function (CreditCardInvoice $invoice) use ($today): ?array {
                $outstanding = $this->invoiceOutstanding($invoice);
                if ($outstanding <= 0) {
                    return null;
                }

                $card = $invoice->creditCard;
                $overdue = $invoice->due_date->lt($today);

                return $this->item(
                    id: 'invoice-'.$invoice->id,
                    source: 'invoice',
                    description: $card === null ? 'Fatura do cartão' : 'Fatura '.$card->name,
                    amount: $this->money($outstanding),
                    date: $invoice->due_date->toDateString(),
                    statusLabel: $overdue ? 'Vencida' : $invoice->status->label(),
                    isOverdue: $overdue,
                    context: $card === null ? 'Cartão de crédito' : $this->cardLabel($card->name, $card->last_four),
                    details: $this->details([
                        'Conta prevista' => $card?->paymentAccount?->name,
                        'Forma prevista' => $card?->invoice_payment_method?->label(),
                        'Instruções' => $card?->payment_instructions,
                        'Valor da fatura' => $this->money($this->invoiceTotal($invoice)),
                        'Já pago' => $this->moneyToDisplay((string) $invoice->paid_amount),
                    ]),
                    children: $this->invoiceChildren($invoice),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function cardPayments(
        Workspace $workspace,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $payments = $workspace->creditCardInvoicePayments()
            ->whereBetween('paid_on', [$start->toDateString(), $end->toDateString()])
            ->with([
                'creditCard:id,name,last_four',
                'account:id,name',
                'invoice:id,credit_card_id,calculated_amount,statement_amount,status',
                'invoice.installments' => fn ($query) => $query
                    ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value),
                'invoice.installments.transaction:id,description,transaction_date',
            ])
            ->get()
            ->groupBy(fn (CreditCardInvoicePayment $payment): string => $payment->credit_card_invoice_id === null
                ? 'payment-'.$payment->id
                : 'invoice-'.$payment->credit_card_invoice_id);

        $items = [];
        foreach ($payments as $group) {
            /** @var CreditCardInvoicePayment $first */
            $first = $group->first();
            /** @var CreditCardInvoicePayment $last */
            $last = $group->sortByDesc('paid_on')->first();
            $invoice = $first->invoice;
            $card = $first->creditCard;
            $amount = $this->sumMoney($group->pluck('amount')->all());
            $description = $invoice === null
                ? ($card === null ? 'Pagamento de cartão' : 'Pagamento cartão '.$card->name)
                : ($card === null ? 'Fatura do cartão' : 'Fatura '.$card->name);

            $items[] = $this->item(
                id: $invoice === null ? 'card-payment-'.$first->id : 'invoice-payment-'.$invoice->id,
                source: $invoice === null ? 'card_payment' : 'invoice',
                description: $description,
                amount: $this->money($amount),
                date: $last->paid_on->toDateString(),
                statusLabel: $invoice === null
                    ? 'Pagamento registrado'
                    : ($invoice->status === CreditCardInvoiceStatus::Paid ? 'Fatura paga' : 'Pagamento parcial'),
                context: $card === null ? 'Cartão de crédito' : $this->cardLabel($card->name, $card->last_four),
                details: $this->details([
                    'Conta de origem' => $last->account?->name,
                    'Forma de pagamento' => $last->payment_method->label(),
                    'Fatura' => $invoice === null ? 'Vínculo pendente' : $this->money($this->invoiceTotal($invoice)),
                ]),
                children: $invoice === null ? [] : $this->invoiceChildren($invoice),
            );
        }

        return $items;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function transactionDetails(FinancialTransaction $entry): array
    {
        return $this->details([
            'Conta' => $entry->account?->name,
            'Forma prevista' => $entry->payment_method?->label(),
            'Favorecido' => $entry->payee_name,
            'Instruções' => $entry->payment_instructions,
            'Origem' => $entry->financial_recurrence_id === null ? null : 'Recorrência',
        ]);
    }

    /** @return array<int, array{label: string, value: string}> */
    private function installmentDetails(TransactionInstallment $installment): array
    {
        $transaction = $installment->transaction;

        return $this->details([
            'Parcela' => $installment->installment_number.' de '.$installment->total_installments,
            'Compra/contrato original' => $transaction->description,
            'Valor original' => $this->moneyToDisplay((string) $transaction->amount),
            'Conta' => $transaction->account?->name,
            'Forma prevista' => $transaction->payment_method?->label(),
        ]);
    }

    /** @return array<int, array<string, string|null>> */
    private function invoiceChildren(CreditCardInvoice $invoice): array
    {
        return $invoice->installments
            ->map(fn (TransactionInstallment $installment): array => [
                'id' => 'installment-'.$installment->id,
                'description' => $installment->transaction?->description ?? 'Compra do cartão',
                'amount' => (string) $installment->amount,
                'date' => $installment->transaction?->transaction_date?->toDateString(),
                'meta' => 'Parcela '.$installment->installment_number.'/'.$installment->total_installments,
            ])
            ->all();
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<int, array{label: string, value: string}>
     */
    private function details(array $values): array
    {
        $result = [];
        foreach ($values as $label => $value) {
            if ($value !== null && trim($value) !== '') {
                $result[] = ['label' => $label, 'value' => $value];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $details
     * @param  array<int, array<string, string|null>>  $children
     * @return array<string, mixed>
     */
    private function item(
        string $id,
        string $source,
        string $description,
        string $amount,
        string $date,
        string $statusLabel,
        bool $isOverdue = false,
        ?string $context = null,
        array $details = [],
        array $children = [],
    ): array {
        return [
            'id' => $id,
            'source' => $source,
            'description' => $description,
            'amount' => $amount,
            'date' => $date,
            'status_label' => $statusLabel,
            'is_overdue' => $isOverdue,
            'context' => $context,
            'details' => $details,
            'children' => $children,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function sortItems(array $items): array
    {
        usort($items, fn (array $left, array $right): int => [
            $left['date'],
            $left['description'],
            $left['id'],
        ] <=> [
            $right['date'],
            $right['description'],
            $right['id'],
        ]);

        return $items;
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function itemsTotal(array $items): int
    {
        return $this->sumMoney(array_column($items, 'amount'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $payable
     * @return array<int, array<string, mixed>>
     */
    private function upcomingGroups(array $payable, CarbonImmutable $today): array
    {
        $groups = [
            'overdue' => ['label' => 'Vencidos', 'items' => []],
            'today' => ['label' => 'Vencendo hoje', 'items' => []],
            'next_7_days' => ['label' => 'Próximos 7 dias', 'items' => []],
            'rest_month' => ['label' => 'Restante do mês', 'items' => []],
        ];
        $sevenDays = $today->addDays(7);

        foreach ($payable as $item) {
            $date = CarbonImmutable::parse((string) $item['date']);
            $key = match (true) {
                $date->lt($today) => 'overdue',
                $date->equalTo($today) => 'today',
                $date->lte($sevenDays) => 'next_7_days',
                default => 'rest_month',
            };
            $groups[$key]['items'][] = $item;
        }

        $result = [];
        foreach ($groups as $key => $group) {
            $result[] = [
                'key' => $key,
                'label' => $group['label'],
                'count' => count($group['items']),
                'amount' => $this->money($this->itemsTotal($group['items'])),
                'item_ids' => array_column($group['items'], 'id'),
            ];
        }

        return $result;
    }

    private function periodStart(Request $request, CarbonImmutable $today): CarbonImmutable
    {
        $period = $request->query('period');
        if (! is_string($period) || preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', $period, $matches) !== 1) {
            return $today->startOfMonth();
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        if ($year < 1990 || $year > 2100 || $month < 1 || $month > 12) {
            return $today->startOfMonth();
        }

        return CarbonImmutable::create($year, $month, 1)->startOfMonth();
    }

    private function invoiceOutstanding(CreditCardInvoice $invoice): int
    {
        return max(0, $this->invoiceTotal($invoice) - $this->moneyToCents((string) $invoice->paid_amount));
    }

    private function invoiceTotal(CreditCardInvoice $invoice): int
    {
        return $this->moneyToCents((string) ($invoice->statement_amount ?? $invoice->calculated_amount));
    }

    private function cardLabel(string $name, ?string $lastFour): string
    {
        return $lastFour === null || $lastFour === '' ? $name : $name.' · final '.$lastFour;
    }

    /** @param  array<int, mixed>  $amounts */
    private function sumMoney(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $this->moneyToCents((string) $amount);
        }

        return $total;
    }

    private function moneyToCents(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        if ($negative) {
            $amount = substr($amount, 1);
        }

        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $decimal;

        return $negative ? -$cents : $cents;
    }

    private function money(int $cents): string
    {
        $absolute = abs($cents);
        $formatted = sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);

        return $cents < 0 ? '-'.$formatted : $formatted;
    }

    private function moneyToDisplay(string $amount): string
    {
        return $this->money($this->moneyToCents($amount));
    }
}
