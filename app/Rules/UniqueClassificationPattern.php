<?php

namespace App\Rules;

use App\Enums\ClassificationRuleMatchType;
use App\Models\Workspace;
use App\Services\Finance\ClassificationRuleMatcher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueClassificationPattern implements ValidationRule
{
    public function __construct(
        private Workspace $workspace,
        private ClassificationRuleMatcher $matcher,
        private ?ClassificationRuleMatchType $matchType,
        private ?int $ignoreId = null,
        private ?int $financialAccountId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->matchType === null || ! is_string($value)) {
            return;
        }

        $duplicate = $this->matcher->findDuplicate(
            $this->workspace,
            $this->matchType,
            $value,
            $this->ignoreId,
            $this->financialAccountId,
        );

        if ($duplicate !== null) {
            $fail('Já existe uma regra com o mesmo texto e a mesma forma de correspondência.');
        }
    }
}
