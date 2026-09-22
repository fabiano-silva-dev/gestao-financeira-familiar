<?php

namespace App\Services\Imports;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Enums\FinancialTransactionOrigin;
use App\Enums\TransactionInstallmentStatus;
use App\Models\AccountMovement;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\FinancialTransaction;
use App\Models\TransactionInstallment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\Detection\FinancialDocumentDetection;
use App\Services\Imports\Detection\ImportTargetResolver;
use App\Services\Reconciliation\BankReconciliationService;
use App\Services\Reconciliation\CardStatementReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ImportedFileDestinationService
{
    public function __construct(
        private readonly BankReconciliationService $bankReconciliation,
        private readonly CardStatementReconciliationService $cardReconciliation,
        private readonly OfxImportService $bankImports,
        private readonly CardStatementImportService $cardImports,
        private readonly ImportTargetResolver $targets,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function reassign(
        Workspace $workspace,
        FinancialImport $import,
        User $user,
        array $data,
    ): FinancialImport {
        abort_unless($import->workspace_id === $workspace->id, 404);
        abort_unless($import->status === FinancialImportStatus::Completed, 404);
        abort_unless(in_array($import->type, [
            FinancialImportType::Ofx,
            FinancialImportType::CardStatement,
        ], true), 404);

        $documentType = (string) $data['document_type'];
        $wantsInvoice = $documentType === 'credit_card_statement';
        $isInvoice = $import->type === FinancialImportType::CardStatement;

        if ($wantsInvoice !== $isInvoice) {
            return $this->reimportAs($workspace, $import, $user, $data, $wantsInvoice);
        }

        if ($wantsInvoice) {
            $card = $workspace->creditCards()->findOrFail((int) $data['credit_card_id']);

            if ($import->credit_card_id === $card->id) {
                return $import;
            }

            return $this->moveInvoice($workspace, $import, $user, $card);
        }

        $account = $workspace->financialAccounts()->findOrFail((int) $data['financial_account_id']);

        if ($import->financial_account_id === $account->id) {
            return $import;
        }

        return $this->moveStatement($workspace, $import, $user, $account);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reimportAs(
        Workspace $workspace,
        FinancialImport $import,
        User $user,
        array $data,
        bool $wantsInvoice,
    ): FinancialImport {
        $file = $this->storedFile($import);
        $existingIds = $workspace->financialImports()->pluck('id');

        try {
            $created = $wantsInvoice
                ? $this->cardImports->import(
                    $workspace,
                    $workspace->creditCards()->findOrFail((int) $data['credit_card_id']),
                    $user,
                    $file,
                    (string) $data['reference_month'],
                    $this->amountSign($import),
                    $this->pdfLayout($import),
                )->import
                : $this->bankImports->import(
                    $workspace,
                    $workspace->financialAccounts()->findOrFail((int) $data['financial_account_id']),
                    $user,
                    $file,
                )->import;
        } catch (ValidationException $exception) {
            $workspace->financialImports()
                ->whereNotIn('id', $existingIds)
                ->get()
                ->each(function (FinancialImport $failed): void {
                    $failed->bankStatementEntries()->delete();
                    $failed->cardStatementEntries()->delete();
                    $failed->delete();
                });
            $message = $exception->validator->errors()->first();

            throw ValidationException::withMessages([
                'document_type' => is_string($message) && $message !== ''
                    ? $message
                    : 'Não foi possível ler este arquivo como o tipo escolhido.',
            ]);
        }

        $this->remember($workspace, $import, $created, $data);
        $this->purge($workspace, $import);

        return $created->refresh();
    }

    private function moveStatement(
        Workspace $workspace,
        FinancialImport $import,
        User $user,
        FinancialAccount $account,
    ): FinancialImport {
        return DB::transaction(function () use ($workspace, $import, $user, $account): FinancialImport {
            $locked = $this->lockImport($workspace, $import);
            $oldAccountId = (int) $locked->financial_account_id;
            $entries = $locked->bankStatementEntries()->orderBy('id')->lockForUpdate()->get();
            $this->guardBankCollision($workspace, $account, $entries);

            foreach ($entries as $entry) {
                $this->retargetBankEntry($workspace, $locked, $entry, $user, $oldAccountId, $account->id);
            }

            $locked->update([
                'financial_account_id' => $account->id,
                'credit_card_id' => null,
            ]);
            $this->rememberTarget($workspace, $locked->refresh(), $account, null);

            return $locked->refresh();
        });
    }

    private function moveInvoice(
        Workspace $workspace,
        FinancialImport $import,
        User $user,
        CreditCard $card,
    ): FinancialImport {
        return DB::transaction(function () use ($workspace, $import, $user, $card): FinancialImport {
            $locked = $this->lockImport($workspace, $import);
            $oldCardId = (int) $locked->credit_card_id;
            $entries = $locked->cardStatementEntries()
                ->with('invoice')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->guardCardCollision($workspace, $card, $entries);
            $touchedInvoices = [];

            foreach ($entries as $entry) {
                $invoice = $entry->invoice;

                if ($invoice instanceof CreditCardInvoice) {
                    $touchedInvoices[$invoice->id] = $invoice;
                }

                $target = $this->retargetCardEntry($workspace, $entry, $user, $card, $oldCardId);

                if ($target instanceof CreditCardInvoice) {
                    $touchedInvoices[$target->id] = $target;
                }
            }

            $referenceMonth = $this->referenceMonth($locked);
            $targetInvoice = $referenceMonth !== null
                ? $this->invoiceFor($card, $referenceMonth)
                : null;

            if ($targetInvoice instanceof CreditCardInvoice) {
                $touchedInvoices[$targetInvoice->id] = $targetInvoice;
                $this->transferStatementAmount($locked, $targetInvoice);
            }

            foreach ($touchedInvoices as $invoice) {
                $this->refreshInvoiceAmount($invoice);
            }

            $locked->update([
                'financial_account_id' => null,
                'credit_card_id' => $card->id,
            ]);
            $this->rememberTarget($workspace, $locked->refresh(), null, $card);

            return $locked->refresh();
        });
    }

    private function retargetBankEntry(
        Workspace $workspace,
        FinancialImport $import,
        BankStatementEntry $entry,
        User $user,
        int $oldAccountId,
        int $newAccountId,
    ): void {
        $movement = $entry->account_movement_id === null
            ? null
            : AccountMovement::query()
                ->with(['transaction.accountMovements', 'invoicePayment', 'refund'])
                ->find($entry->account_movement_id);
        $relink = $movement instanceof AccountMovement
            && $this->movementFollowsImport($import, $movement, $oldAccountId, $newAccountId);

        if ($movement instanceof AccountMovement && ($entry->is_reconciled || $entry->account_movement_id !== null)) {
            $this->bankReconciliation->undo($workspace, $entry);
            $entry->refresh();
            $movement->refresh();
        }

        if ($relink) {
            $transaction = $movement->transaction;

            if ($transaction instanceof FinancialTransaction) {
                $transaction->load('accountMovements');

                foreach ($transaction->accountMovements as $related) {
                    $this->moveMovementAccount($related, $oldAccountId, $newAccountId);
                }

                $transaction->update([
                    'financial_account_id' => $this->replacedId($transaction->financial_account_id, $oldAccountId, $newAccountId),
                    'source_account_id' => $this->replacedId($transaction->source_account_id, $oldAccountId, $newAccountId),
                    'destination_account_id' => $this->replacedId($transaction->destination_account_id, $oldAccountId, $newAccountId),
                ]);
            } else {
                $this->moveMovementAccount($movement, $oldAccountId, $newAccountId);
            }
        }

        $entry->update(['financial_account_id' => $newAccountId]);

        if (! $relink || ! $movement instanceof AccountMovement) {
            return;
        }

        $this->bankReconciliation->reconcile(
            $workspace,
            $entry->refresh(),
            $movement->refresh(),
            $user,
        );
    }

    private function movementFollowsImport(
        FinancialImport $import,
        AccountMovement $movement,
        int $oldAccountId,
        int $newAccountId,
    ): bool {
        $transaction = $movement->transaction;

        if (! $transaction instanceof FinancialTransaction) {
            return false;
        }

        if ($transaction->origin !== FinancialTransactionOrigin::Ofx) {
            return false;
        }

        $movements = $transaction->accountMovements;
        $outside = BankStatementEntry::query()
            ->whereIn('account_movement_id', $movements->pluck('id'))
            ->where('financial_import_id', '!=', $import->id)
            ->exists();

        if ($outside) {
            return false;
        }

        return $movements->every(
            fn (AccountMovement $related): bool => in_array((int) $related->financial_account_id, [
                $oldAccountId,
                $newAccountId,
            ], true),
        );
    }

    private function moveMovementAccount(
        AccountMovement $movement,
        int $oldAccountId,
        int $newAccountId,
    ): void {
        if ((int) $movement->financial_account_id !== $oldAccountId) {
            return;
        }

        $movement->update(['financial_account_id' => $newAccountId]);
        $payment = $movement->invoicePayment;

        if ($payment !== null && (int) $payment->financial_account_id === $oldAccountId) {
            $payment->update(['financial_account_id' => $newAccountId]);
        }

        $refund = $movement->refund;

        if ($refund !== null && (int) $refund->destination_account_id === $oldAccountId) {
            $refund->update(['destination_account_id' => $newAccountId]);
        }
    }

    private function retargetCardEntry(
        Workspace $workspace,
        CardStatementEntry $entry,
        User $user,
        CreditCard $card,
        int $oldCardId,
    ): ?CreditCardInvoice {
        $currentInvoice = $entry->invoice;
        $month = $currentInvoice instanceof CreditCardInvoice
            ? $currentInvoice->reference_month->format('Y-m')
            : null;
        $targetInvoice = $month !== null ? $this->invoiceFor($card, $month) : null;
        $installment = $entry->transaction_installment_id === null
            ? null
            : TransactionInstallment::query()->with('transaction.installments.invoice')->find($entry->transaction_installment_id);
        $relink = $installment instanceof TransactionInstallment
            && $targetInvoice instanceof CreditCardInvoice
            && $this->installmentFollowsImport(
                $installment,
                $oldCardId,
                $card->id,
                (int) $entry->financial_import_id,
            );

        if (
            $currentInvoice instanceof CreditCardInvoice
            && ($entry->is_reconciled || $entry->transaction_installment_id !== null)
        ) {
            $this->cardReconciliation->undo($workspace, $currentInvoice, $entry);
            $entry->refresh();
        }

        if ($relink && $installment instanceof TransactionInstallment) {
            $transaction = $installment->transaction;

            if (
                $transaction instanceof FinancialTransaction
                && (int) $transaction->credit_card_id === $oldCardId
            ) {
                $transaction->update(['credit_card_id' => $card->id]);

                foreach ($transaction->installments as $related) {
                    $relatedInvoice = $related->invoice;

                    if (! $relatedInvoice instanceof CreditCardInvoice) {
                        continue;
                    }

                    if ((int) $relatedInvoice->credit_card_id !== $oldCardId) {
                        continue;
                    }

                    $related->update([
                        'credit_card_invoice_id' => $this->invoiceFor(
                            $card,
                            $relatedInvoice->reference_month->format('Y-m'),
                        )->id,
                    ]);
                }
            }
        }

        if ($targetInvoice instanceof CreditCardInvoice) {
            $entry->update([
                'credit_card_id' => $card->id,
                'credit_card_invoice_id' => $targetInvoice->id,
            ]);
        } else {
            $entry->update(['credit_card_id' => $card->id]);
        }

        if (
            ! $relink
            || ! $installment instanceof TransactionInstallment
            || ! $targetInvoice instanceof CreditCardInvoice
        ) {
            return $targetInvoice;
        }

        $this->cardReconciliation->reconcile(
            $workspace,
            $targetInvoice,
            $entry->refresh(),
            $installment->refresh(),
            $user,
        );

        return $targetInvoice;
    }

    private function installmentFollowsImport(
        TransactionInstallment $installment,
        int $oldCardId,
        int $newCardId,
        int $importId,
    ): bool {
        $transaction = $installment->transaction;

        if (
            ! $transaction instanceof FinancialTransaction
            || $transaction->origin !== FinancialTransactionOrigin::CardImport
        ) {
            return false;
        }

        if (! in_array((int) $transaction->credit_card_id, [$oldCardId, $newCardId], true)) {
            return false;
        }

        $installmentIds = $transaction->installments->pluck('id');

        return ! CardStatementEntry::query()
            ->whereIn('transaction_installment_id', $installmentIds)
            ->where('financial_import_id', '!=', $importId)
            ->exists();
    }

    private function purge(Workspace $workspace, FinancialImport $import): void
    {
        DB::transaction(function () use ($workspace, $import): void {
            $locked = $this->lockImport($workspace, $import);
            $transactionIds = [];

            foreach ($locked->bankStatementEntries()->with('accountMovement.transaction')->lockForUpdate()->get() as $entry) {
                $transaction = $entry->accountMovement?->transaction;

                if (
                    $transaction instanceof FinancialTransaction
                    && $transaction->origin === FinancialTransactionOrigin::Ofx
                    && $this->transactionBelongsToImport($transaction, $locked)
                ) {
                    $transactionIds[] = $transaction->id;
                }

                if ($entry->is_reconciled || $entry->account_movement_id !== null) {
                    $this->bankReconciliation->undo($workspace, $entry);
                }
            }

            foreach ($locked->cardStatementEntries()->with(['invoice', 'transactionInstallment.transaction'])->lockForUpdate()->get() as $entry) {
                $transaction = $entry->transactionInstallment?->transaction;
                $invoice = $entry->invoice;

                if (
                    $transaction instanceof FinancialTransaction
                    && $transaction->origin === FinancialTransactionOrigin::CardImport
                    && $this->cardTransactionBelongsToImport($transaction, $locked)
                ) {
                    $transactionIds[] = $transaction->id;
                }

                if (
                    $invoice instanceof CreditCardInvoice
                    && ($entry->is_reconciled || $entry->transaction_installment_id !== null)
                ) {
                    $this->cardReconciliation->undo($workspace, $invoice, $entry);
                }
            }

            if ($transactionIds !== []) {
                FinancialTransaction::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('id', array_unique($transactionIds))
                    ->delete();
            }

            $locked->bankStatementEntries()->delete();
            $locked->cardStatementEntries()->delete();
            $locked->delete();
        });
    }

    private function transactionBelongsToImport(
        FinancialTransaction $transaction,
        FinancialImport $import,
    ): bool {
        $movementIds = $transaction->accountMovements()->pluck('id');

        if ($movementIds->isEmpty()) {
            return false;
        }

        return ! BankStatementEntry::query()
            ->whereIn('account_movement_id', $movementIds)
            ->where('financial_import_id', '!=', $import->id)
            ->exists();
    }

    private function cardTransactionBelongsToImport(
        FinancialTransaction $transaction,
        FinancialImport $import,
    ): bool {
        $installmentIds = $transaction->installments()->pluck('id');

        if ($installmentIds->isEmpty()) {
            return false;
        }

        return ! CardStatementEntry::query()
            ->whereIn('transaction_installment_id', $installmentIds)
            ->where('financial_import_id', '!=', $import->id)
            ->exists();
    }

    /** @param  array<string, mixed>  $data */
    private function remember(
        Workspace $workspace,
        FinancialImport $source,
        FinancialImport $created,
        array $data,
    ): void {
        $stored = data_get($source->metadata, 'autodetection');

        if (! is_array($stored)) {
            return;
        }

        $detection = FinancialDocumentDetection::fromArray($stored);
        $documentType = (string) $data['document_type'];
        $metadata = $created->metadata ?? [];
        $metadata['autodetection'] = [
            ...$detection->toArray(),
            'document_type' => $documentType,
            'confirmed_by_user' => true,
        ];
        $created->update(['metadata' => $metadata]);
        $account = $documentType === 'credit_card_statement'
            ? null
            : $created->financialAccount;
        $card = $documentType === 'credit_card_statement'
            ? $created->creditCard
            : null;
        $this->targets->replaceDestination($workspace, $detection, $documentType, $account, $card);
    }

    private function rememberTarget(
        Workspace $workspace,
        FinancialImport $import,
        ?FinancialAccount $account,
        ?CreditCard $card,
    ): void {
        $stored = data_get($import->metadata, 'autodetection');

        if (! is_array($stored)) {
            return;
        }

        $detection = FinancialDocumentDetection::fromArray($stored);
        $documentType = $card instanceof CreditCard
            ? 'credit_card_statement'
            : ($detection->documentType === 'payment_account_statement'
                ? 'payment_account_statement'
                : 'bank_statement');
        $this->targets->replaceDestination($workspace, $detection, $documentType, $account, $card);
    }

    private function lockImport(Workspace $workspace, FinancialImport $import): FinancialImport
    {
        return FinancialImport::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($import->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param Collection<int, BankStatementEntry> $entries */
    private function guardBankCollision(
        Workspace $workspace,
        FinancialAccount $account,
        $entries,
    ): void {
        $keys = $entries->pluck('deduplication_key')->filter()->values();

        if ($keys->isEmpty()) {
            return;
        }

        $collision = BankStatementEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('financial_account_id', $account->id)
            ->whereIn('deduplication_key', $keys)
            ->exists();

        if ($collision) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'Esta conta já tem os movimentos deste arquivo.',
            ]);
        }
    }

    /** @param Collection<int, CardStatementEntry> $entries */
    private function guardCardCollision(
        Workspace $workspace,
        CreditCard $card,
        $entries,
    ): void {
        $keys = $entries->pluck('deduplication_key')->filter()->values();

        if ($keys->isEmpty()) {
            return;
        }

        $collision = CardStatementEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('credit_card_id', $card->id)
            ->whereIn('deduplication_key', $keys)
            ->exists();

        if ($collision) {
            throw ValidationException::withMessages([
                'credit_card_id' => 'Este cartão já tem as linhas deste arquivo.',
            ]);
        }
    }

    private function invoiceFor(CreditCard $card, string $referenceMonth): CreditCardInvoice
    {
        $reference = CarbonImmutable::parse($referenceMonth.'-01')->startOfMonth();
        $dueDate = $this->dateInMonth($reference, $card->due_day);
        $closingMonth = $card->due_day > $card->closing_day
            ? $reference
            : $reference->subMonth();
        $closingDate = $this->dateInMonth($closingMonth, $card->closing_day);

        return CreditCardInvoice::query()->firstOrCreate(
            [
                'workspace_id' => $card->workspace_id,
                'credit_card_id' => $card->id,
                'reference_month' => $reference->toDateString(),
            ],
            [
                'closing_date' => $closingDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'calculated_amount' => '0.00',
                'statement_amount' => null,
                'paid_amount' => '0.00',
                'status' => CreditCardInvoiceStatus::Open,
            ],
        );
    }

    private function dateInMonth(CarbonImmutable $month, int $day): CarbonImmutable
    {
        $base = $month->startOfMonth();

        return $base->addDays(min($day, $base->daysInMonth) - 1);
    }

    private function transferStatementAmount(
        FinancialImport $import,
        CreditCardInvoice $target,
    ): void {
        $metadata = $import->metadata ?? [];
        $amount = $metadata['statement_amount'] ?? null;
        $applied = ($metadata['statement_amount_applied'] ?? false) === true;
        $oldId = $metadata['credit_card_invoice_id'] ?? null;
        $metadata['credit_card_invoice_id'] = $target->id;

        if ($applied && is_string($amount) && is_numeric($oldId)) {
            $old = CreditCardInvoice::query()->find((int) $oldId);

            if ($old instanceof CreditCardInvoice && (string) $old->statement_amount === $amount) {
                $old->update(['statement_amount' => null]);
            }

            if ($target->statement_amount === null) {
                $target->update(['statement_amount' => $amount]);
            }
        }

        $import->update(['metadata' => $metadata]);
    }

    private function refreshInvoiceAmount(CreditCardInvoice $invoice): void
    {
        $fresh = $invoice->fresh();

        if (! $fresh instanceof CreditCardInvoice) {
            return;
        }

        $amount = (string) $fresh->installments()
            ->where('status', '!=', TransactionInstallmentStatus::Cancelled->value)
            ->sum('amount');
        $fresh->update([
            'calculated_amount' => $this->normalizeMoney($amount),
        ]);
    }

    private function normalizeMoney(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;

        return ($negative ? '-' : '').$whole.'.'.substr(str_pad($decimal, 2, '0'), 0, 2);
    }

    private function referenceMonth(FinancialImport $import): ?string
    {
        $month = data_get($import->metadata, 'reference_month');

        return is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1
            ? $month
            : null;
    }

    private function amountSign(FinancialImport $import): string
    {
        $sign = data_get($import->metadata, 'amount_sign');

        return in_array($sign, ['positive', 'negative', 'auto'], true) ? $sign : 'auto';
    }

    private function pdfLayout(FinancialImport $import): ?string
    {
        $layout = data_get($import->metadata, 'pdf_layout');

        return is_string($layout) && $layout !== '' ? $layout : null;
    }

    private function storedFile(FinancialImport $import): UploadedFile
    {
        if (! is_string($import->stored_path) || $import->stored_path === '') {
            throw ValidationException::withMessages([
                'document_type' => 'O arquivo original não está mais disponível. Importe de novo.',
            ]);
        }

        $path = Storage::disk('local')->path($import->stored_path);

        if (! is_file($path)) {
            throw ValidationException::withMessages([
                'document_type' => 'O arquivo original não está mais disponível. Importe de novo.',
            ]);
        }

        return new UploadedFile($path, $import->source_filename, null, null, true);
    }

    private function replacedId(mixed $current, int $oldId, int $newId): ?int
    {
        if ($current === null) {
            return null;
        }

        return (int) $current === $oldId ? $newId : (int) $current;
    }
}
