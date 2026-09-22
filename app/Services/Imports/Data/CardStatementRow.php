<?php

namespace App\Services\Imports\Data;

final readonly class CardStatementRow
{
    /**
     * @param  array<string, string|null>  $rawData
     */
    public function __construct(
        public string $purchasedOn,
        public string $description,
        public string $amount,
        public ?int $installmentNumber,
        public ?int $totalInstallments,
        public ?string $externalId,
        public array $rawData,
        public ?string $sourceCategory = null,
    ) {}
}
