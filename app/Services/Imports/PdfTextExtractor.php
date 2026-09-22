<?php

namespace App\Services\Imports;

use Illuminate\Support\Facades\Process;

final class PdfTextExtractor
{
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
            'Não foi possível ler o texto do PDF. Por enquanto, o PDF suportado é o extrato de conta corrente do Banrisul.',
        );
    }

    private function extractWithPdftotext(string $path): string
    {
        $process = Process::timeout(30)->run([
            'pdftotext',
            '-layout',
            $path,
            '-',
        ]);

        if (! $process->successful()) {
            return '';
        }

        return trim(str_replace("\f", "\n", $process->output()));
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
