<?php

namespace App\Services\Finance;

use App\Enums\ClassificationRuleMatchType;
use App\Enums\FinancialTransactionType;
use App\Models\ClassificationRule;
use App\Models\Workspace;
use Illuminate\Support\Collection;

final class ClassificationRuleMatcher
{
    /**
     * @var list<string>
     */
    private const PREFIXES = [
        'transferencia eletronica disponivel',
        'transferencia eletronica',
        'transferencia enviada',
        'transferencia recebida',
        'transferencia para conta',
        'transferencia para',
        'transferencia',
        'pagamento enviado',
        'pagamento recebido',
        'pagamento',
        'compra no cartao',
        'compra debito',
        'debito automatico',
        'debito em conta',
        'credito em conta',
        'pix enviado',
        'pix recebido',
        'pix',
        'ted',
        'doc',
        'tef',
        'tarifa',
        'debito',
        'credito',
    ];

    /**
     * @return array{
     *     rule_id: int,
     *     action_type: string,
     *     payee_name: string|null,
     *     category_id: int|null,
     *     counterpart_account_id: int|null
     * }|null
     */
    public function match(
        Workspace $workspace,
        string $description,
        ?int $financialAccountId = null,
    ): ?array {
        $rules = $workspace->classificationRules()
            ->where('is_active', true)
            ->where(function ($query) use ($financialAccountId): void {
                $query->where('action_type', '!=', FinancialTransactionType::Transfer->value);

                if ($financialAccountId !== null) {
                    $query->orWhere(function ($transfer) use ($financialAccountId): void {
                        $transfer
                            ->where('action_type', FinancialTransactionType::Transfer->value)
                            ->where('financial_account_id', $financialAccountId);
                    });
                }
            })
            ->orderBy('id')
            ->get([
                'id',
                'match_type',
                'pattern',
                'action_type',
                'payee_name',
                'category_id',
                'counterpart_account_id',
            ]);

        $matched = $this->ranked($rules)
            ->first(fn (ClassificationRule $rule): bool => $this->matches(
                $rule->match_type,
                $rule->pattern,
                $description,
            ));

        if (! $matched instanceof ClassificationRule) {
            return null;
        }

        $payee = is_string($matched->payee_name) && trim($matched->payee_name) !== ''
            ? trim($matched->payee_name)
            : null;

        return [
            'rule_id' => $matched->id,
            'action_type' => $matched->action_type->value,
            'payee_name' => $payee,
            'category_id' => $this->nullableId($matched->category_id),
            'counterpart_account_id' => $this->nullableId($matched->counterpart_account_id),
        ];
    }

    public function findDuplicate(
        Workspace $workspace,
        ClassificationRuleMatchType $matchType,
        string $pattern,
        ?int $ignoreId = null,
        ?int $financialAccountId = null,
    ): ?ClassificationRule {
        $normalized = $this->normalize($pattern);

        if ($normalized === '') {
            return null;
        }

        return $workspace->classificationRules()
            ->when(
                $ignoreId !== null,
                fn ($query) => $query->where('id', '!=', $ignoreId),
            )
            ->where('match_type', $matchType->value)
            ->when(
                $financialAccountId !== null,
                fn ($query) => $query->where('financial_account_id', $financialAccountId),
                fn ($query) => $query->whereNull('financial_account_id'),
            )
            ->orderBy('id')
            ->get(['id', 'name', 'match_type', 'pattern'])
            ->first(
                fn (ClassificationRule $rule): bool => $this->normalize($rule->pattern) === $normalized,
            );
    }

    public function matches(
        ClassificationRuleMatchType $type,
        string $pattern,
        string $description,
    ): bool {
        $normalizedDescription = $this->normalize($description);
        $normalizedPattern = $this->normalize($pattern);

        if ($normalizedDescription === '' || $normalizedPattern === '') {
            return false;
        }

        return match ($type) {
            ClassificationRuleMatchType::Equals => $normalizedDescription === $normalizedPattern,
            ClassificationRuleMatchType::StartsWith => str_starts_with(
                $normalizedDescription,
                $normalizedPattern,
            ),
            ClassificationRuleMatchType::Contains => str_contains(
                $normalizedDescription,
                $normalizedPattern,
            ),
            ClassificationRuleMatchType::ContainsAllWords => $this->containsWords(
                $normalizedDescription,
                $normalizedPattern,
                true,
            ),
            ClassificationRuleMatchType::ContainsAnyWord => $this->containsWords(
                $normalizedDescription,
                $normalizedPattern,
                false,
            ),
        };
    }

    /**
     * @return array{name: string, pattern: string, match_type: string}
     */
    public function suggest(string $description, ?string $payeeName = null): array
    {
        $normalized = $this->normalize($description);
        $stripped = $this->stripPrefixes($normalized);
        $pattern = $stripped !== '' ? $stripped : $normalized;
        $original = $this->originalSlice($description, $pattern) ?? $pattern;
        $payee = is_string($payeeName) && trim($payeeName) !== ''
            ? trim($payeeName)
            : null;

        return [
            'name' => $payee ?? $this->titleFromPattern($original),
            'pattern' => $original,
            'match_type' => ClassificationRuleMatchType::Contains->value,
        ];
    }

    public function normalize(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($transliterated) ? $transliterated : $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @param  Collection<int, ClassificationRule>  $rules
     * @return Collection<int, ClassificationRule>
     */
    private function ranked(Collection $rules): Collection
    {
        return $rules
            ->sort(function (ClassificationRule $left, ClassificationRule $right): int {
                $specificity = $this->specificity($right) <=> $this->specificity($left);

                return $specificity !== 0
                    ? $specificity
                    : $left->id <=> $right->id;
            })
            ->values();
    }

    private function specificity(ClassificationRule $rule): int
    {
        $pattern = $this->normalize($rule->pattern);
        $length = mb_strlen($pattern);
        $words = count($this->words($pattern));

        return match ($rule->match_type) {
            ClassificationRuleMatchType::Equals => 500 + $length,
            ClassificationRuleMatchType::StartsWith => 400 + $length,
            ClassificationRuleMatchType::Contains => 300 + $length,
            ClassificationRuleMatchType::ContainsAllWords => 200 + ($words * 20) + $length,
            ClassificationRuleMatchType::ContainsAnyWord => 50 + $length,
        };
    }

    private function containsWords(string $description, string $pattern, bool $requireAll): bool
    {
        $haystack = ' '.$description.' ';
        $words = $this->words($pattern);

        if ($words === []) {
            return false;
        }

        $matched = 0;

        foreach ($words as $word) {
            if (str_contains($haystack, ' '.$word.' ')) {
                $matched++;
            }
        }

        return $requireAll
            ? $matched === count($words)
            : $matched > 0;
    }

    /**
     * @return list<string>
     */
    private function words(string $normalized): array
    {
        return array_values(array_filter(
            explode(' ', $normalized),
            fn (string $word): bool => $word !== '',
        ));
    }

    private function stripPrefixes(string $normalized): string
    {
        $value = $normalized;

        foreach (self::PREFIXES as $prefix) {
            if ($value === $prefix) {
                return '';
            }

            if (str_starts_with($value, $prefix.' ')) {
                $value = trim(substr($value, strlen($prefix)));
            }
        }

        return $value;
    }

    private function originalSlice(string $description, string $normalizedPattern): ?string
    {
        $tokens = $this->words($normalizedPattern);

        if ($tokens === []) {
            return null;
        }

        $quoted = array_map(
            fn (string $token): string => preg_quote($token, '/'),
            $tokens,
        );
        $regex = '/'.implode('\s+', $quoted).'/iu';

        if (preg_match($regex, $description, $matches) !== 1) {
            return null;
        }

        return trim($matches[0]);
    }

    private function titleFromPattern(string $pattern): string
    {
        $trimmed = trim($pattern);

        if ($trimmed === '') {
            return 'Nova regra';
        }

        return mb_strlen($trimmed) <= 120
            ? $trimmed
            : trim(mb_substr($trimmed, 0, 117)).'...';
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
