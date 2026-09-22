<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class BankStatementFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Envie um arquivo de extrato válido.');

            return;
        }

        if (! in_array(strtolower($value->getClientOriginalExtension()), ['ofx', 'qfx', 'csv', 'pdf'], true)) {
            $fail('O extrato deve possuir a extensão .ofx, .qfx, .csv ou .pdf.');
        }
    }
}
