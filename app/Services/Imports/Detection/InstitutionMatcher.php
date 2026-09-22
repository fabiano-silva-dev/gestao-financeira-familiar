<?php

namespace App\Services\Imports\Detection;

final class InstitutionMatcher
{
    /** @var array<string, list<string>> */
    private const ALIASES = [
        'mercado_pago' => ['mercado pago', 'mercadopago'],
        'nubank' => ['nubank', 'nu pagamentos', 'nu financeira'],
        'banrisul' => ['banrisul', 'banco do estado do rio grande do sul'],
        'sicredi' => ['sicredi', 'banco cooperativo sicredi'],
        'inter' => ['banco inter', 'inter bank'],
        'pagbank' => ['pagbank', 'pagseguro', 'pag seguro'],
        'carrefour' => ['carrefour', 'banco csf'],
    ];

    /** @var array<string, string> */
    private const BANK_IDS = [
        '041' => 'banrisul',
        '748' => 'sicredi',
        '260' => 'nubank',
        '077' => 'inter',
        '290' => 'pagbank',
        '323' => 'mercado_pago',
        '368' => 'carrefour',
    ];

    public function detect(string $value): ?string
    {
        $normalized = $this->normalize($value);

        foreach (self::ALIASES as $institution => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($normalized, $this->normalize($alias))) {
                    return $institution;
                }
            }
        }

        return null;
    }

    public function fromBankId(?string $bankId): ?string
    {
        if ($bankId === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $bankId) ?? '';
        $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);

        return self::BANK_IDS[$digits] ?? null;
    }

    public function matches(?string $detectedInstitution, ?string $storedInstitution): bool
    {
        if ($detectedInstitution === null || $storedInstitution === null) {
            return false;
        }

        $storedDetected = $this->detect($storedInstitution);

        if ($storedDetected !== null) {
            return $storedDetected === $detectedInstitution;
        }

        return str_contains(
            $this->normalize($storedInstitution),
            $this->normalize(str_replace('_', ' ', $detectedInstitution)),
        );
    }

    public function normalize(string $value): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($converted) ? $converted : $value;
        $value = mb_strtolower($value);

        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value);
    }
}
