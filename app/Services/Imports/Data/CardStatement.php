<?php

namespace App\Services\Imports\Data;

final readonly class CardStatement
{
    /**
     * @param  array<int, CardStatementRow>  $rows
     * @param  array<int, string>  $headers
     */
    public function __construct(
        public array $rows,
        public array $headers,
        public string $sourceFormat,
        public int $ignoredRows,
    ) {}
}
