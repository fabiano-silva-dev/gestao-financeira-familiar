<?php

namespace App\Services\Imports;

final class PdfReadContext
{
    private ?string $password = null;

    /** @var list<string> */
    private array $candidates = [];

    private ?string $resolved = null;

    public function password(): ?string
    {
        return $this->password;
    }

    /**
     * @return list<string|null>
     */
    public function attempts(): array
    {
        if ($this->password !== null) {
            return [$this->password];
        }

        if ($this->resolved !== null) {
            return [$this->resolved];
        }

        return array_merge([null], $this->candidates);
    }

    public function remember(string $password): void
    {
        if ($this->password === null) {
            $this->resolved = $password;
        }
    }

    public function failureMessage(): string
    {
        if ($this->password !== null) {
            return 'A senha do PDF está incorreta.';
        }

        if ($this->candidates !== []) {
            return 'Este PDF está protegido por senha e não abriu com os 5 primeiros dígitos dos CPFs cadastrados. Informe a senha do arquivo.';
        }

        return 'Este PDF está protegido por senha. Informe a senha do arquivo. No Mercado Pago, ela é os 5 primeiros dígitos do CPF.';
    }

    /**
     * @template T
     *
     * @param  list<string>  $candidates
     * @param  callable(): T  $callback
     * @return T
     */
    public function using(?string $password, array $candidates, callable $callback): mixed
    {
        $previousPassword = $this->password;
        $previousCandidates = $this->candidates;
        $previousResolved = $this->resolved;
        $this->password = $password !== null && $password !== '' ? $password : null;
        $this->candidates = $this->normalizeCandidates($candidates);
        $this->resolved = null;

        try {
            return $callback();
        } finally {
            $this->password = $previousPassword;
            $this->candidates = $previousCandidates;
            $this->resolved = $previousResolved;
        }
    }

    /**
     * @return list<string>
     */
    public function candidates(): array
    {
        return $this->candidates;
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function normalizeCandidates(array $candidates): array
    {
        $normalized = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '' || strlen($candidate) > 64) {
                continue;
            }

            $normalized[$candidate] = $candidate;
        }

        return array_values($normalized);
    }
}
