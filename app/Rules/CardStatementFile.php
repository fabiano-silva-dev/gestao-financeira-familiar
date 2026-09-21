<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class CardStatementFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Envie um arquivo de fatura válido.');

            return;
        }

        if (! in_array(strtolower($value->getClientOriginalExtension()), ['csv', 'xls', 'xlsx'], true)) {
            $fail('A fatura deve possuir a extensão .csv, .xls ou .xlsx.');
        }
    }
}
