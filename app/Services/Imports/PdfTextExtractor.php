<?php

namespace App\Services\Imports;

use Illuminate\Support\Facades\Process;

final class PdfTextExtractor
{
    public function __construct(
        private readonly ?PdfReadContext $passwords = null,
    ) {}

    public function extract(string $contents): string
    {
        if (trim($contents) === '') {
            throw new BankStatementParseException('O arquivo PDF está vazio.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'stmt-pdf-');

        if ($temporary === false || file_put_contents($temporary, $contents) === false) {
            throw new BankStatementParseException('Não foi possível preparar o PDF para leitura.');
        }

        try {
            $fromPoppler = $this->extractWithPdftotext($temporary);

            if ($fromPoppler !== '') {
                return $fromPoppler;
            }
        } finally {
            @unlink($temporary);
        }

        $fromLiterals = $this->extractStringLiterals($contents);

        if ($fromLiterals !== '') {
            return $fromLiterals;
        }

        throw new BankStatementParseException(
            'Não foi possível ler o texto do PDF.',
        );
    }

    private function extractWithPdftotext(string $path): string
    {
        $context = $this->passwords;
        $attempts = $context?->attempts() ?? [null];
        $protected = false;

        foreach ($attempts as $password) {
            $command = ['pdftotext', '-layout'];

            if (is_string($password) && $password !== '') {
                $command[] = '-upw';
                $command[] = $password;
            }

            $command[] = $path;
            $command[] = '-';

            $process = Process::timeout(30)->run($command);

            if ($process->successful()) {
                if (is_string($password) && $password !== '') {
                    $context?->remember($password);
                }

                return trim(str_replace("\f", "\n", $process->output()));
            }

            if (str_contains($process->errorOutput(), 'Incorrect password')) {
                $protected = true;

                continue;
            }

            return '';
        }

        if ($protected) {
            throw new PdfPasswordException(
                $context?->failureMessage()
                    ?? 'Este PDF está protegido por senha. Informe a senha do arquivo.',
            );
        }

        return '';
    }

    private function extractStringLiterals(string $contents): string
    {
        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $contents, $matches) < 1) {
            return '';
        }

        $lines = [];

        foreach ($matches[0] as $token) {
            if (preg_match('/^\((.*)\)\s*Tj$/s', $token, $inner) !== 1) {
                continue;
            }

            $decoded = stripcslashes(str_replace('\\)', ')', str_replace('\\(', '(', $inner[1])));
            $decoded = trim($decoded);

            if ($decoded !== '') {
                $lines[] = $decoded;
            }
        }

        return implode("\n", $lines);
    }
}
