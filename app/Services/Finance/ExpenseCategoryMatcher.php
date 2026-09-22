<?php

namespace App\Services\Finance;

use App\Enums\CategoryType;
use App\Enums\FinancialTransactionStatus;
use App\Enums\FinancialTransactionType;
use App\Models\Category;
use App\Models\FinancialTransaction;
use App\Models\Workspace;
use Illuminate\Support\Collection;

final class ExpenseCategoryMatcher
{
    /**
     * Palavras da fatura associadas ao nome normalizado de uma categoria já cadastrada.
     *
     * @var array<string, string>
     */
    private const KEYWORDS = [
        '99app' => 'transporte',
        '99 ride' => 'transporte',
        'uber' => 'transporte',
        '99pop' => 'transporte',
        'localiza' => 'transporte',
        'viasul' => 'transporte',
        'rota santa maria' => 'transporte',
        'parking' => 'transporte',
        'estaciona' => 'transporte',
        'pedagio' => 'transporte',
        'supermerc' => 'mercado',
        'stangherlin' => 'mercado',
        'conveniencia' => 'mercado',
        'pao quente' => 'mercado',
        'farmacia' => 'farmacia',
        'raia' => 'farmacia',
        'drogaria' => 'farmacia',
        'netflix' => 'entreterimento',
        'spotify' => 'entreterimento',
        'academia' => 'esporte',
        'dojo' => 'esporte',
        'karate' => 'esporte',
        'volei' => 'esporte',
        'handebol' => 'esporte',
        'presentes' => 'presentes',
        'unicesumar' => 'educacao',
        'escola' => 'escola',
    ];

    public function match(
        Workspace $workspace,
        string $description,
        ?string $sourceCategory = null,
    ): ?int {
        $categories = $workspace->categories()
            ->where('type', CategoryType::Expense->value)
            ->where('is_active', true)
            ->get(['id', 'name']);

        if ($categories->isEmpty()) {
            return null;
        }

        if (is_string($sourceCategory) && trim($sourceCategory) !== '') {
            $fromSource = $this->matchCategoryName($sourceCategory, $categories);

            if ($fromSource !== null) {
                return $fromSource;
            }
        }

        $fromHistory = $this->knownMerchantCategoryId($workspace, $description);

        if ($fromHistory !== null) {
            return $fromHistory;
        }

        $fromDescription = $this->matchCategoryName($description, $categories);

        if ($fromDescription !== null) {
            return $fromDescription;
        }

        return $this->matchKeywords($description, $categories);
    }

    /**
     * @param  Collection<int, Category>  $categories
     */
    private function matchCategoryName(string $value, Collection $categories): ?int
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return null;
        }

        $bestId = null;
        $bestScore = 0.0;

        foreach ($categories as $category) {
            $name = $this->normalize($category->name);

            if ($name === '') {
                continue;
            }

            $score = 0.0;

            if ($normalized === $name) {
                $score = 1.0;
            } elseif (str_contains($normalized, $name) && mb_strlen($name) >= 5) {
                $score = 0.92;
            } elseif (str_contains($name, $normalized) && mb_strlen($normalized) >= 5) {
                $score = 0.88;
            } else {
                similar_text($normalized, $name, $percentage);
                $score = mb_strlen($normalized) >= 6 && mb_strlen($name) >= 6
                    ? $percentage / 100
                    : 0.0;
            }

            if ($score >= 0.82 && $score > $bestScore) {
                $bestScore = $score;
                $bestId = (int) $category->id;
            }
        }

        return $bestId;
    }

    /**
     * @param  Collection<int, Category>  $categories
     */
    private function matchKeywords(string $description, Collection $categories): ?int
    {
        $normalized = $this->normalize($description);

        foreach (self::KEYWORDS as $keyword => $hint) {
            if (! str_contains($normalized, $keyword)) {
                continue;
            }

            $matched = $this->matchCategoryName($hint, $categories);

            if ($matched !== null) {
                return $matched;
            }
        }

        return null;
    }

    private function knownMerchantCategoryId(
        Workspace $workspace,
        string $description,
    ): ?int {
        $normalized = $this->normalize($description);

        if ($normalized === '') {
            return null;
        }

        $match = $workspace->financialTransactions()
            ->where('type', FinancialTransactionType::Expense->value)
            ->whereNotNull('category_id')
            ->where('status', '!=', FinancialTransactionStatus::Cancelled->value)
            ->latest('id')
            ->limit(500)
            ->get(['id', 'category_id', 'description', 'payee_name'])
            ->first(function (FinancialTransaction $transaction) use ($normalized): bool {
                $payee = $transaction->getAttribute('payee_name');
                $payee = is_string($payee) && $payee !== ''
                    ? $payee
                    : (string) $transaction->getAttribute('description');
                $payeeNormalized = $this->normalize($payee);
                $descriptionNormalized = $this->normalize(
                    (string) $transaction->getAttribute('description'),
                );

                return $this->merchantMatches($normalized, $payeeNormalized)
                    || $this->merchantMatches($normalized, $descriptionNormalized);
            });

        if (! $match instanceof FinancialTransaction) {
            return null;
        }

        $categoryId = $match->getAttribute('category_id');

        return is_int($categoryId) ? $categoryId : (int) $categoryId;
    }

    private function merchantMatches(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        $shorter = mb_strlen($left) <= mb_strlen($right) ? $left : $right;
        $longer = $shorter === $left ? $right : $left;

        return mb_strlen($shorter) >= 6 && str_contains($longer, $shorter);
    }

    private function normalize(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($transliterated) ? $transliterated : $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
