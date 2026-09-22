<?php

namespace App\Services\Imports;

use App\Enums\FinancialImportStatus;
use App\Enums\FinancialImportType;
use App\Models\FinancialImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\Detection\FinancialDocumentDetection;
use App\Services\Imports\Detection\FinancialDocumentDetectorRegistry;
use App\Services\Imports\Detection\ImportTargetResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FinancialDocumentImportService
{
    private const AUTO_CONFIDENCE = 0.85;

    public function __construct(
        private readonly FinancialDocumentDetectorRegistry $detectors,
        private readonly ImportTargetResolver $targets,
        private readonly OfxImportService $bankImports,
        private readonly CardStatementImportService $cardImports,
    ) {}

    /**
     * @return array{status: string, import: FinancialImport, detection: FinancialDocumentDetection}
     */
    public function import(
        Workspace $workspace,
        User $user,
        UploadedFile $file,
    ): array {
        $contents = $file->get();

        if ($contents === false) {
            throw ValidationException::withMessages([
                'files' => 'Não foi possível ler um dos arquivos enviados.',
            ]);
        }

        $fileHash = hash('sha256', $contents);
        $alreadyImported = $workspace->financialImports()
            ->where('file_hash', $fileHash)
            ->where('status', FinancialImportStatus::Completed->value)
            ->latest('id')
            ->first();

        if ($alreadyImported instanceof FinancialImport) {
            $storedDetection = data_get($alreadyImported->metadata, 'autodetection');
            $detection = FinancialDocumentDetection::fromArray(
                is_array($storedDetection) ? $storedDetection : [],
            );

            return [
                'status' => 'duplicate',
                'import' => $alreadyImported,
                'detection' => $detection,
            ];
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $detection = $this->detectors->detect(
            $contents,
            $file->getClientOriginalName(),
            $extension,
            $file->getMimeType(),
        );

        try {
            $processed = $this->processAutomatically(
                $workspace,
                $user,
                $file,
                $detection,
                $extension,
            );

            if ($processed instanceof FinancialImport) {
                return [
                    'status' => 'processed',
                    'import' => $processed,
                    'detection' => $detection,
                ];
            }
        } catch (ValidationException $exception) {
            return [
                'status' => 'needs_confirmation',
                'import' => $this->storePending(
                    $workspace,
                    $user,
                    $file,
                    $contents,
                    $detection,
                    $exception->getMessage(),
                ),
                'detection' => $detection,
            ];
        }

        return [
            'status' => 'needs_confirmation',
            'import' => $this->storePending($workspace, $user, $file, $contents, $detection),
            'detection' => $detection,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function resolvePending(
        Workspace $workspace,
        FinancialImport $pending,
        User $user,
        array $data,
    ): FinancialImport {
        abort_unless($pending->workspace_id === $workspace->id, 404);
        abort_unless(
            $pending->type === FinancialImportType::Document
                && $pending->status === FinancialImportStatus::NeedsConfirmation,
            404,
        );

        $storedDetection = data_get($pending->metadata, 'autodetection');
        $original = FinancialDocumentDetection::fromArray(
            is_array($storedDetection) ? $storedDetection : [],
        );
        $documentType = (string) $data['document_type'];
        $detection = new FinancialDocumentDetection(
            documentType: $documentType,
            institution: $original->institution,
            confidence: $original->confidence,
            format: $original->format,
            parserKey: $this->resolvedParserKey($original, $documentType),
            identifierType: $original->identifierType,
            identifierValue: $original->identifierValue,
            referenceMonth: is_string($data['reference_month'] ?? null)
                ? $data['reference_month']
                : $original->referenceMonth,
            holderName: $original->holderName,
            metadata: $original->metadata,
        );
        $extension = $this->extension($pending->source_filename);

        if (! $this->parserSupported($detection, $extension)) {
            throw ValidationException::withMessages([
                'document_type' => 'O documento foi identificado, mas ainda não existe parser seguro para este layout.',
            ]);
        }

        $file = $this->pendingUploadedFile($pending);

        if ($detection->importKind() === 'statement') {
            $account = $workspace->financialAccounts()
                ->findOrFail((int) $data['financial_account_id']);
            $result = $this->bankImports->import($workspace, $account, $user, $file);
            $this->targets->learnAccount($workspace, $detection, $account);
            $finalImport = $result->import;
        } else {
            $card = $workspace->creditCards()
                ->findOrFail((int) $data['credit_card_id']);
            $referenceMonth = $detection->referenceMonth;

            if ($referenceMonth === null) {
                throw ValidationException::withMessages([
                    'reference_month' => 'Informe o mês de vencimento da fatura.',
                ]);
            }

            $result = $this->cardImports->import(
                $workspace,
                $card,
                $user,
                $file,
                $referenceMonth,
                'auto',
                $this->pdfLayout($detection),
            );
            $this->targets->learnCard($workspace, $detection, $card);
            $finalImport = $result->import;
        }

        $this->attachDetection($finalImport, $detection, true);
        $storedPath = $pending->stored_path;
        $pending->delete();

        if (is_string($storedPath) && $storedPath !== '') {
            Storage::disk('local')->delete($storedPath);
        }

        return $finalImport->refresh();
    }

    private function processAutomatically(
        Workspace $workspace,
        User $user,
        UploadedFile $file,
        FinancialDocumentDetection $detection,
        string $extension,
    ): ?FinancialImport {
        if ($detection->confidence < self::AUTO_CONFIDENCE) {
            return null;
        }

        if (! $this->parserSupported($detection, $extension)) {
            return null;
        }

        if ($detection->importKind() === 'statement') {
            $account = $this->targets->resolveAccount($workspace, $detection);

            if ($account === null) {
                return null;
            }

            $result = $this->bankImports->import($workspace, $account, $user, $file);
            $this->targets->learnAccount($workspace, $detection, $account);
            $this->attachDetection($result->import, $detection, false);

            return $result->import->refresh();
        }

        if ($detection->importKind() === 'invoice') {
            $card = $this->targets->resolveCard($workspace, $detection);

            if ($card === null || $detection->referenceMonth === null) {
                return null;
            }

            $result = $this->cardImports->import(
                $workspace,
                $card,
                $user,
                $file,
                $detection->referenceMonth,
                'auto',
                $this->pdfLayout($detection),
            );
            $this->targets->learnCard($workspace, $detection, $card);
            $this->attachDetection($result->import, $detection, false);

            return $result->import->refresh();
        }

        return null;
    }

    private function storePending(
        Workspace $workspace,
        User $user,
        UploadedFile $file,
        string $contents,
        FinancialDocumentDetection $detection,
        ?string $processingError = null,
    ): FinancialImport {
        $fileHash = hash('sha256', $contents);
        $deduplicationKey = hash('sha256', 'document|file:'.$fileHash);
        $existing = $workspace->financialImports()
            ->where('type', FinancialImportType::Document->value)
            ->where('deduplication_key', $deduplicationKey)
            ->where('status', FinancialImportStatus::NeedsConfirmation->value)
            ->first();

        if ($existing instanceof FinancialImport) {
            return $existing;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $storedPath = "imports/{$workspace->id}/pending/{$fileHash}.{$extension}";

        if (! Storage::disk('local')->put($storedPath, $contents)) {
            throw ValidationException::withMessages([
                'files' => 'Não foi possível armazenar o documento pendente.',
            ]);
        }

        return FinancialImport::query()->create([
            'workspace_id' => $workspace->id,
            'financial_account_id' => null,
            'credit_card_id' => null,
            'created_by' => $user->id,
            'type' => FinancialImportType::Document,
            'status' => FinancialImportStatus::NeedsConfirmation,
            'source_filename' => Str::limit(
                basename(str_replace('\\', '/', $file->getClientOriginalName())),
                255,
                '',
            ),
            'stored_path' => $storedPath,
            'file_hash' => $fileHash,
            'deduplication_key' => $deduplicationKey,
            'total_records' => 0,
            'imported_records' => 0,
            'duplicate_records' => 0,
            'metadata' => array_filter([
                'autodetection' => $detection->toArray(),
                'missing_fields' => $this->missingFields($workspace, $detection),
                'processing_error' => $processingError,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    /** @return list<string> */
    private function missingFields(
        Workspace $workspace,
        FinancialDocumentDetection $detection,
    ): array {
        $missing = [];
        $kind = $detection->importKind();

        if ($kind === null) {
            return ['document_type'];
        }

        if ($detection->parserKey === null) {
            $missing[] = 'parser';
        }

        if ($kind === 'statement' && $this->targets->resolveAccount($workspace, $detection) === null) {
            $missing[] = 'financial_account_id';
        }

        if ($kind === 'invoice') {
            if ($this->targets->resolveCard($workspace, $detection) === null) {
                $missing[] = 'credit_card_id';
            }

            if ($detection->referenceMonth === null) {
                $missing[] = 'reference_month';
            }
        }

        return $missing;
    }

    private function attachDetection(
        FinancialImport $import,
        FinancialDocumentDetection $detection,
        bool $confirmedByUser,
    ): void {
        $metadata = $import->metadata ?? [];
        $metadata['autodetection'] = [
            ...$detection->toArray(),
            'confirmed_by_user' => $confirmedByUser,
        ];
        $import->update(['metadata' => $metadata]);
    }

    private function pendingUploadedFile(FinancialImport $pending): UploadedFile
    {
        if (! is_string($pending->stored_path) || $pending->stored_path === '') {
            throw ValidationException::withMessages([
                'file' => 'O arquivo pendente não está mais disponível.',
            ]);
        }

        $path = Storage::disk('local')->path($pending->stored_path);

        if (! is_file($path)) {
            throw ValidationException::withMessages([
                'file' => 'O arquivo pendente não está mais disponível.',
            ]);
        }

        return new UploadedFile($path, $pending->source_filename, null, null, true);
    }

    private function resolvedParserKey(
        FinancialDocumentDetection $detection,
        string $documentType,
    ): ?string {
        if ($detection->documentType === $documentType && $detection->parserKey !== null) {
            return $detection->parserKey;
        }

        return match ($documentType) {
            'bank_statement', 'payment_account_statement' => match ($detection->format) {
                'ofx', 'qfx' => 'ofx',
                'csv' => 'bank_csv',
                'pdf' => $detection->institution === 'banrisul'
                    ? 'banrisul_current_account'
                    : null,
                default => null,
            },
            'credit_card_statement' => match ($detection->format) {
                'csv', 'xls', 'xlsx' => 'card_table',
                'pdf' => $detection->institution === 'mercado_pago'
                    ? 'mercado_pago_credit_card'
                    : null,
                default => null,
            },
            default => null,
        };
    }

    private function parserSupported(
        FinancialDocumentDetection $detection,
        string $extension,
    ): bool {
        return match ($detection->parserKey) {
            'ofx' => in_array($extension, ['ofx', 'qfx'], true),
            'bank_csv' => $extension === 'csv',
            'banrisul_current_account' => $extension === 'pdf',
            'card_table' => in_array($extension, ['csv', 'xls', 'xlsx'], true),
            'mercado_pago_credit_card' => $extension === 'pdf',
            default => false,
        };
    }

    private function pdfLayout(FinancialDocumentDetection $detection): ?string
    {
        return $detection->format === 'pdf' ? $detection->parserKey : null;
    }

    private function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}
