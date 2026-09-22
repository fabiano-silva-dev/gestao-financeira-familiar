<?php

namespace App\Services\Imports;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\BankStatementEntry;
use App\Models\FinancialAccount;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\Data\OfxImportResult;
use App\Services\Imports\Data\OfxTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

final class OfxImportService
{
    public function __construct(
        private readonly BankStatementParser $parser,
        private readonly FinancialImportProcessor $processor,
    ) {}

    public function import(
        Workspace $workspace,
        FinancialAccount $account,
        User $user,
        UploadedFile $file,
    ): OfxImportResult {
        $contents = $file->get();

        if ($contents === false) {
            throw ValidationException::withMessages([
                'file' => 'Não foi possível ler o arquivo enviado.',
            ]);
        }

        $fileHash = hash('sha256', $contents);
        $this->rejectIfAlreadyImported($workspace, $account, $fileHash);

        $deduplicationKey = hash(
            'sha256',
            "ofx|account:{$account->id}|file:{$fileHash}",
        );
        $existing = $workspace->financialImports()
            ->where('type', FinancialImportType::Ofx->value)
            ->where('deduplication_key', $deduplicationKey)
            ->first();

        $extension = strtolower($file->getClientOriginalExtension() ?: 'ofx');
        $storedPath = "imports/{$workspace->id}/statements/{$fileHash}.{$extension}";

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
            'financial_account_id' => $account->id,
            'credit_card_id' => null,
            'created_by' => $user->id,
            'type' => FinancialImportType::Ofx,
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
            'metadata' => null,
            'error_message' => null,
            'imported_at' => null,
        ]);
        $financialImport->save();

        try {
            $statement = $this->parser->parse($contents, $extension);

            DB::transaction(function () use (
                $financialImport,
                $workspace,
                $account,
                $statement,
                $extension,
            ): void {
                $lockedImport = FinancialImport::query()
                    ->whereKey($financialImport->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $imported = 0;
                $duplicates = 0;

                foreach ($statement->transactions as $transaction) {
                    $entry = BankStatementEntry::query()->firstOrCreate(
                        [
                            'workspace_id' => $workspace->id,
                            'financial_account_id' => $account->id,
                            'deduplication_key' => $this->entryDeduplicationKey($transaction),
                        ],
                        [
                            'financial_import_id' => $lockedImport->id,
                            'external_id' => $transaction->externalId,
                            'occurred_on' => $transaction->occurredOn,
                            'amount' => $transaction->amount,
                            'transaction_type' => $transaction->transactionType,
                            'description' => $transaction->description,
                            'memo' => $transaction->memo,
                            'is_reconciled' => false,
                        ],
                    );

                    $entry->wasRecentlyCreated ? $imported++ : $duplicates++;
                }

                $lockedImport->update([
                    'status' => FinancialImportStatus::Completed,
                    'total_records' => count($statement->transactions),
                    'imported_records' => $imported,
                    'duplicate_records' => $duplicates,
                    'statement_start_on' => $statement->startOn,
                    'statement_end_on' => $statement->endOn,
                    'external_account_identifier' => $statement->accountId,
                    'metadata' => array_filter([
                        'bank_id' => $statement->bankId,
                        'currency' => $statement->currency,
                        'source_format' => $extension,
                    ], static fn (mixed $value): bool => $value !== null),
                    'error_message' => null,
                    'imported_at' => now(),
                ]);
            });
        } catch (OfxParseException|BankStatementParseException $exception) {
            $this->markAsFailed($financialImport, $exception->getMessage());

            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->markAsFailed(
                $financialImport,
                'O arquivo não pôde ser processado. Tente novamente.',
            );
            report($exception);

            throw ValidationException::withMessages([
                'file' => 'O arquivo não pôde ser processado. Tente novamente.',
            ]);
        }

        $this->processor->process($workspace, $financialImport->refresh(), $user);

        return new OfxImportResult($financialImport->refresh(), false);
    }

    private function rejectIfAlreadyImported(
        Workspace $workspace,
        FinancialAccount $account,
        string $fileHash,
    ): void {
        $alreadyImported = $workspace->financialImports()
            ->where('file_hash', $fileHash)
            ->where('financial_account_id', $account->id)
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

    private function entryDeduplicationKey(OfxTransaction $transaction): string
    {
        if ($transaction->externalId !== null) {
            return hash('sha256', 'fitid|'.$transaction->externalId);
        }

        $normalize = static fn (?string $value): string => mb_strtolower(
            preg_replace('/\s+/u', ' ', trim($value ?? '')) ?? trim($value ?? ''),
        );

        return hash('sha256', implode('|', [
            'fallback',
            $transaction->occurredOn,
            $transaction->amount,
            $transaction->transactionType,
            $normalize($transaction->description),
            $normalize($transaction->memo),
            $normalize($transaction->checkNumber),
            $normalize($transaction->referenceNumber),
        ]));
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
