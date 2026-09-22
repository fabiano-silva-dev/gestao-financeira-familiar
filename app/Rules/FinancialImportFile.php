<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

final class FinancialImportFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Envie um arquivo financeiro válido.');

            return;
        }

        if (! in_array(
            strtolower($value->getClientOriginalExtension()),
            ['ofx', 'qfx', 'csv', 'pdf', 'xls', 'xlsx'],
            true,
        )) {
            $fail('O arquivo deve ser OFX, QFX, CSV, PDF, XLS ou XLSX.');
        }

        if ($value->getSize() > 10 * 1024 * 1024) {
            $fail('O arquivo deve possuir no máximo 10 MB.');
        }
    }
}
