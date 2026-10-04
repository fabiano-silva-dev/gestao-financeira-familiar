<?php

namespace App\Services\Imports;

use App\Models\Workspace;

final class FamilyDocumentPasswordSource
{
    /**
     * Senha de PDF do Mercado Pago: os 5 primeiros dígitos do CPF.
     *
     * @return list<string>
     */
    public function mercadoPagoPasswords(Workspace $workspace): array
    {
        $passwords = [];

        foreach ($workspace->familyMembers()->whereNotNull('cpf')->get(['cpf']) as $member) {
            $digits = preg_replace('/\D/', '', (string) $member->cpf) ?? '';

            if (strlen($digits) >= 5) {
                $passwords[substr($digits, 0, 5)] = substr($digits, 0, 5);
            }
        }

        return array_values($passwords);
    }
}
