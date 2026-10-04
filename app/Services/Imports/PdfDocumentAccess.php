<?php

namespace App\Services\Imports;

use App\Models\Workspace;

final class PdfDocumentAccess
{
    public function __construct(
        private readonly PdfReadContext $context,
        private readonly FamilyDocumentPasswordSource $passwords,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function open(Workspace $workspace, ?string $password, callable $callback): mixed
    {
        $candidates = $this->context->candidates();

        if ($candidates === []) {
            $candidates = $this->passwords->mercadoPagoPasswords($workspace);
        }

        return $this->context->using(
            $password ?? $this->context->password(),
            $candidates,
            $callback,
        );
    }
}
