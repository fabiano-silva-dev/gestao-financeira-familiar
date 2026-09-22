<?php

namespace App\Http\Requests;

class UpdateClassificationRuleRequest extends StoreClassificationRuleRequest
{
    protected function ignoreRuleId(): ?int
    {
        $rule = $this->route('rule');

        return is_numeric($rule) ? (int) $rule : null;
    }
}
