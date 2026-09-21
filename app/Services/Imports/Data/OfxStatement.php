<?php

namespace App\Services\Imports\Data;

final readonly class OfxStatement
{
    /**
     * @param  array<int, OfxTransaction>  $transactions
     */
    public function __construct(
        public ?string $bankId,
        public ?string $accountId,
        public ?string $currency,
        public ?string $startOn,
        public ?string $endOn,
        public array $transactions,
    ) {}
}
