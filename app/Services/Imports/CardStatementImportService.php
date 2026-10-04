<?php

namespace App\Services\Imports;

use App\Enums\CreditCardInvoiceStatus;
use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\CardStatementEntry;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\CardStatementAiEnrichmentService;
use App\Services\Finance\CreditCardInvoiceService;
use App\Services\Imports\Data\CardStatementImportResult;
use App\Services\Imports\Data\CardStatementRow;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

final class CardStatementImportService
{
    public function __construct(
        private readonly CardStatementParser $parser,
        private readonly FinancialImportProcessor $processor,
        private readonly CardStatementAiEnrichmentService $aiEnrichmentService,
        private readonly CreditCardInvoiceService $invoiceService,
        private readonly PdfDocumentAccess $pdfAccess,
    ) {}

    public function import(
        Workspace $workspace,
        CreditCard $card,
        User $user,
        UploadedFile $file,
        string $referenceMonth,
        string $amountSign,
        ?string $pdfLayout = null,
    ): CardStatementImportResult {
        $contents = $file->get();

        if ($contents === false) {
            throw ValidationException::withMessages([
                'file' => 'Não foi possível ler o arquivo enviado.',
            ]);
        }

        $fileHash = hash('sha256', $contents);
        $this->rejectIfAlreadyImported($workspace, $card, $fileHash);

        $deduplicationKey = hash('sha256', implode('|', [
            'card_statement',
            "card:{$card->id}",
            "reference:{$referenceMonth}",
            "sign:{$amountSign}",
            "file:{$fileHash}",
        ]));
        $existing = $workspace->financialImports()
            ->where('type', FinancialImportType::CardStatement->value)
            ->where('deduplication_key', $deduplicationKey)
            ->first();

        $extension = strtolower($file->getClientOriginalExtension());
        $storedPath = "imports/{$workspace->id}/cards/{$card->id}/{$referenceMonth}/{$fileHash}.{$extension}";

        if (! Storage::disk('local')->put($storedPath, $contents)) {
            throw ValidationException::withMessages([
                'file' => 'Não foi possível armazenar o arquivo com segurança.',
            ]);
        }

        $sourceFilename = Str::limit(
            basename(str_replace('\\', '/', $file->getClientOriginalName())),
            255,
            '',
        );
        $financialImport = $existing ?? new FinancialImport;
        $financialImport->fill([
            'workspace_id' => $workspace->id,
            'financial_account_id' => null,
            'credit_card_id' => $card->id,
            'created_by' => $user->id,
            'type' => FinancialImportType::CardStatement,
            'status' => FinancialImportStatus::Processing,
            'source_filename' => $sourceFilename,
            'stored_path' => $storedPath,
            'file_hash' => $fileHash,
            'deduplication_key' => $deduplicationKey,
            'total_records' => 0,
            'imported_records' => 0,
            'duplicate_records' => 0,
            'statement_start_on' => null,
            'statement_end_on' => null,
            'external_account_identifier' => null,
            'metadata' => [
                'reference_month' => $referenceMonth,
                'amount_sign' => $amountSign,
                'pdf_layout' => $pdfLayout,
            ],
            'error_message' => null,
            'imported_at' => null,
        ]);
        $financialImport->save();

        try {
            $statement = $this->pdfAccess->open(
                $workspace,
                null,
                fn () => $this->parser->parse($contents, $extension, $amountSign, $pdfLayout),
            );

            DB::transaction(function () use (
                $financialImport,
                $workspace,
                $card,
                $referenceMonth,
                $amountSign,
                $pdfLayout,
                $statement,
                $user,
            ): void {
                $lockedImport = FinancialImport::query()
                    ->whereKey($financialImport->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $invoice = $this->resolveInvoice($card, $referenceMonth);
                $imported = 0;
                $duplicates = 0;
                $coveredEntryIds = [];
                $occurrences = [];
                $statementCents = 0;
                $firstRow = $statement->rows[0] ?? throw new CardStatementParseException(
                    'Nenhum lançamento válido foi encontrado na fatura.',
                );
                $statementStartOn = $firstRow->purchasedOn;
                $statementEndOn = $firstRow->purchasedOn;

                foreach ($statement->rows as $row) {
                    if (! $row->isPayment) {
                        $statementCents += $this->moneyToCents($row->amount);
                    }
                    $statementStartOn = $row->purchasedOn < $statementStartOn
                        ? $row->purchasedOn
                        : $statementStartOn;
                    $statementEndOn = $row->purchasedOn > $statementEndOn
                        ? $row->purchasedOn
                        : $statementEndOn;
                    $signature = $this->entrySignature($row);
                    $occurrences[$signature] = ($occurrences[$signature] ?? 0) + 1;
                    $deduplicationKey = $this->entryDeduplicationKey(
                        $row,
                        $referenceMonth,
                        $occurrences[$signature],
                    );
                    $entry = CardStatementEntry::query()->firstOrCreate(
                        [
                            'workspace_id' => $workspace->id,
                            'credit_card_id' => $card->id,
                            'deduplication_key' => $deduplicationKey,
                        ],
                        [
                            'financial_import_id' => $lockedImport->id,
                            'credit_card_invoice_id' => $invoice->id,
                            'purchased_on' => $row->purchasedOn,
                            'description' => $row->description,
                            'amount' => $row->amount,
                            'installment_number' => $row->installmentNumber,
                            'total_installments' => $row->totalInstallments,
                            'external_id' => $row->externalId,
                            'raw_data' => $row->rawData,
                            'is_payment' => $row->isPayment,
                            'is_reconciled' => false,
                        ],
                    );

                    if ($row->isPayment && ! $entry->is_reconciled) {
                        $this->invoiceService->registerImportedPaymentEvidence(
                            $card,
                            $entry,
                            $user,
                        );
                    }

                    $coveredEntryIds[] = $entry->id;

                    if ($entry->wasRecentlyCreated) {
                        $imported++;
                    } else {
                        $duplicates++;
                    }
                }

                $statementAmount = $statement->statementAmount
                    ?? $this->centsToMoney($statementCents);
                $statementApplied = $invoice->status === CreditCardInvoiceStatus::Open;

                if ($statementApplied) {
                    $invoice->update(['statement_amount' => $statementAmount]);
                }

                $lockedImport->update([
                    'status' => FinancialImportStatus::Completed,
                    'total_records' => count($statement->rows),
                    'imported_records' => $imported,
                    'duplicate_records' => $duplicates,
                    'statement_start_on' => $statementStartOn,
                    'statement_end_on' => $statementEndOn,
                    'metadata' => [
                        'reference_month' => $referenceMonth,
                        'amount_sign' => $amountSign,
                        'pdf_layout' => $pdfLayout,
                        'source_format' => $statement->sourceFormat,
                        'headers' => $statement->headers,
                        'ignored_rows' => $statement->ignoredRows,
                        'statement_amount' => $statementAmount,
                        'statement_amount_applied' => $statementApplied,
                        'credit_card_invoice_id' => $invoice->id,
                        'covered_card_entry_ids' => $coveredEntryIds,
                    ],
                    'error_message' => null,
                    'imported_at' => now(),
                ]);
            });
        } catch (CardStatementParseException $exception) {
            $this->markAsFailed($financialImport, $exception->getMessage());

            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        } catch (PdfPasswordException $exception) {
            $this->markAsFailed($financialImport, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $this->markAsFailed(
                $financialImport,
                'A fatura não pôde ser processada. Tente novamente.',
            );
            report($exception);

            throw ValidationException::withMessages([
                'file' => 'A fatura não pôde ser processada. Tente novamente.',
            ]);
        }

        $initialSummary = $this->processor->process(
            $workspace,
            $financialImport->refresh(),
            $user,
        );
        $this->enrichWithAiSafely($workspace, $financialImport);

        if ($this->hasCategorizedPendingEntries($financialImport)) {
            $secondSummary = $this->processor->process(
                $workspace,
                $financialImport->refresh(),
                $user,
            );
            $this->processor->refreshSummary(
                $workspace,
                $financialImport->refresh(),
                $this->combineActionCounters($initialSummary, $secondSummary),
            );
        } else {
            $this->processor->refreshSummary(
                $workspace,
                $financialImport->refresh(),
            );
        }

        $invoice = CreditCardInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('credit_card_id', $card->id)
            ->where('reference_month', $referenceMonth.'-01')
            ->first();

        if ($invoice instanceof CreditCardInvoice) {
            $this->invoiceService->autoLinkPendingPayments($invoice);
        }

        return new CardStatementImportResult($financialImport->refresh(), false);
    }

    private function rejectIfAlreadyImported(
        Workspace $workspace,
        CreditCard $card,
        string $fileHash,
    ): void {
        $alreadyImported = $workspace->financialImports()
            ->where('file_hash', $fileHash)
            ->where('credit_card_id', $card->id)
            ->where('status', FinancialImportStatus::Completed)
            ->exists();

        if (! $alreadyImported) {
            return;
        }

        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => 'Este arquivo já foi importado.',
        ]);

        throw ValidationException::withMessages([
            'file' => 'Este arquivo já foi importado. Envie um arquivo diferente.',
        ]);
    }

    private function hasCategorizedPendingEntries(
        FinancialImport $financialImport,
    ): bool {
        return $financialImport->cardStatementEntries()
            ->where('is_reconciled', false)
            ->where('is_ignored', false)
            ->whereNotNull('suggested_category_id')
            ->exists();
    }

    /**
     * @param array<string, int|string> $first
     * @param array<string, int|string> $second
     * @return array<string, int>
     */
    private function combineActionCounters(array $first, array $second): array
    {
        $keys = [
            'matched_existing',
            'new_transactions_created',
            'transfers_identified',
            'invoice_payments_identified',
            'refunds_identified',
        ];
        $combined = [];

        foreach ($keys as $key) {
            $combined[$key] = (int) ($first[$key] ?? 0)
                + (int) ($second[$key] ?? 0);
        }

        return $combined;
    }

    private function enrichWithAiSafely(
        Workspace $workspace,
        FinancialImport $financialImport,
    ): void {
        try {
            $this->aiEnrichmentService->enrich($workspace, $financialImport);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function resolveInvoice(
        CreditCard $card,
        string $referenceMonth,
    ): CreditCardInvoice {
        $reference = CarbonImmutable::parse($referenceMonth.'-01')->startOfMonth();
        $dueDate = $this->dateInMonth($reference, $card->due_day);
        $closingMonth = $card->due_day > $card->closing_day
            ? $reference
            : $reference->subMonth();
        $closingDate = $this->dateInMonth($closingMonth, $card->closing_day);

        return $this->invoiceService->resolveOrCreateOpenInvoice(
            $card,
            $referenceMonth,
        );
    }

    private function dateInMonth(
        CarbonImmutable $month,
        int $day,
    ): CarbonImmutable {
        $base = $month->startOfMonth();

        return $base->addDays(min($day, $base->daysInMonth) - 1);
    }

    /**
     * Linhas do arquivo, inclusive as que já existiam em uma importação anterior.
     *
     * @return list<int>
     */
    public function coveredCardEntryIds(FinancialImport $import): array
    {
        $stored = $import->metadata['covered_card_entry_ids'] ?? null;

        if (is_array($stored)) {
            return $this->integerIds($stored);
        }

        $ids = $this->matchStoredCardEntries($import);

        if ($ids === []) {
            return [];
        }

        $metadata = $import->metadata ?? [];
        $metadata['covered_card_entry_ids'] = $ids;
        $import->update(['metadata' => $metadata]);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function matchStoredCardEntries(FinancialImport $import): array
    {
        $referenceMonth = $import->metadata['reference_month'] ?? null;
        $amountSign = $import->metadata['amount_sign'] ?? null;

        if (
            $import->credit_card_id === null
            || ! is_string($referenceMonth)
            || ! is_string($amountSign)
            || ! is_string($import->stored_path)
            || $import->stored_path === ''
            || ! Storage::disk('local')->exists($import->stored_path)
        ) {
            return [];
        }

        $contents = Storage::disk('local')->get($import->stored_path);

        if (! is_string($contents) || $contents === '') {
            return [];
        }

        $extension = strtolower(pathinfo($import->stored_path, PATHINFO_EXTENSION));
        $layout = $import->metadata['pdf_layout'] ?? null;
        $pdfLayout = is_string($layout) ? $layout : null;

        try {
            $statement = $this->pdfAccess->open(
                $import->workspace,
                null,
                fn () => $this->parser->parse($contents, $extension, $amountSign, $pdfLayout),
            );
        } catch (CardStatementParseException) {
            return [];
        }

        $occurrences = [];
        $keys = [];

        foreach ($statement->rows as $row) {
            $signature = $this->entrySignature($row);
            $occurrences[$signature] = ($occurrences[$signature] ?? 0) + 1;
            $keys[] = $this->entryDeduplicationKey(
                $row,
                $referenceMonth,
                $occurrences[$signature],
            );
        }

        if ($keys === []) {
            return [];
        }

        return $this->integerIds(
            CardStatementEntry::query()
                ->where('workspace_id', $import->workspace_id)
                ->where('credit_card_id', $import->credit_card_id)
                ->whereIn('deduplication_key', $keys)
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    private function integerIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $normalized[] = (int) $id;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function entrySignature(CardStatementRow $row): string
    {
        return implode('|', [
            $row->purchasedOn,
            $row->amount,
            $this->normalize($row->description),
            $row->installmentNumber ?? '',
            $row->totalInstallments ?? '',
        ]);
    }

    private function entryDeduplicationKey(
        CardStatementRow $row,
        string $referenceMonth,
        int $occurrence,
    ): string {
        if ($row->externalId !== null) {
            return hash('sha256', implode('|', [
                'external',
                $referenceMonth,
                $this->normalize($row->externalId),
                $row->installmentNumber ?? '',
                $row->totalInstallments ?? '',
            ]));
        }

        return hash('sha256', implode('|', [
            'fallback',
            $referenceMonth,
            $this->entrySignature($row),
            "occurrence:{$occurrence}",
        ]));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(
            preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value),
        );
    }

    private function moneyToCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    private function markAsFailed(
        FinancialImport $financialImport,
        string $message,
    ): void {
        $financialImport->update([
            'status' => FinancialImportStatus::Failed,
            'error_message' => Str::limit($message, 500, ''),
            'imported_at' => null,
        ]);
    }
}
