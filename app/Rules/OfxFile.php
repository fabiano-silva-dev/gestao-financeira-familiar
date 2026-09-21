<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class OfxFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Envie um arquivo OFX válido.');

            return;
        }

        if (! in_array(strtolower($value->getClientOriginalExtension()), ['ofx', 'qfx'], true)) {
            $fail('O arquivo deve possuir a extensão .ofx ou .qfx.');
        }
    }
}
