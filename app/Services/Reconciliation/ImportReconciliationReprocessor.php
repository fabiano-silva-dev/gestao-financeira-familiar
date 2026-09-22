<?php

namespace App\Services\Reconciliation;

use App\Enums\FinancialImportStatus;
use App\Models\BankStatementEntry;
use App\Models\CardStatementEntry;
use App\Models\CreditCardInvoice;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\FinancialImportProcessor;
use Illuminate\Support\Facades\DB;

final class ImportReconciliationReprocessor
{
    public function __construct(
        private readonly BankReconciliationService $bankReconciliation,
        private readonly CardStatementReconciliationService $cardReconciliation,
        private readonly FinancialImportProcessor $processor,
    ) {}

    /** @return array{reopened: int, processed: int} */
    public function reprocess(
        Workspace $workspace,
        FinancialImport $import,
        User $user,
    ): array {
        abort_unless($import->workspace_id === $workspace->id, 404);
        abort_unless($import->status === FinancialImportStatus::Completed, 404);

        $reopened = DB::transaction(function () use ($workspace, $import): int {
            $bankEntries = $import->bankStatementEntries()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $cardEntries = $import->cardStatementEntries()
                ->with('invoice')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($bankEntries as $entry) {
                $this->reopenBank($workspace, $entry);
            }

            foreach ($cardEntries as $entry) {
                $this->reopenCard($workspace, $entry);
            }

            return $bankEntries->count() + $cardEntries->count();
        });

        $summary = $this->processor->process($workspace, $import->refresh(), $user);

        return [
            'reopened' => $reopened,
            'processed' => (int) ($summary['new_items'] ?? 0),
        ];
    }

    private function reopenBank(Workspace $workspace, BankStatementEntry $entry): void
    {
        if ($entry->is_reconciled || $entry->account_movement_id !== null) {
            $this->bankReconciliation->undo($workspace, $entry);
            $entry->refresh();
        }

        $this->clearIgnored($entry);
    }

    private function reopenCard(Workspace $workspace, CardStatementEntry $entry): void
    {
        $invoice = $entry->invoice;

        if (
            $invoice instanceof CreditCardInvoice
            && ($entry->is_reconciled || $entry->transaction_installment_id !== null)
        ) {
            $this->cardReconciliation->undo($workspace, $invoice, $entry);
            $entry->refresh();
        }

        $this->clearIgnored($entry);
    }

    private function clearIgnored(BankStatementEntry|CardStatementEntry $entry): void
    {
        if (! $entry->is_ignored) {
            return;
        }

        $entry->update([
            'is_ignored' => false,
            'ignored_by' => null,
            'ignored_at' => null,
        ]);
    }
}
