<?php

namespace App\Services\Imports\Data;

final readonly class OfxTransaction
{
    public function __construct(
        public string $occurredOn,
        public string $amount,
        public string $transactionType,
        public ?string $externalId,
        public string $description,
        public ?string $memo,
        public ?string $checkNumber,
        public ?string $referenceNumber,
    ) {}
}
