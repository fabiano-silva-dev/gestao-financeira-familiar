<?php

namespace App\Http\Controllers;

use App\Enums\CategoryType;
use App\Enums\ClassificationRuleMatchType;
use App\Enums\FinancialTransactionType;
use App\Http\Requests\StoreClassificationRuleRequest;
use App\Http\Requests\UpdateClassificationRuleRequest;
use App\Models\Category;
use App\Models\ClassificationRule;
use App\Models\FinancialAccount;
use App\Models\Workspace;
use App\Services\Finance\ClassificationRuleMatcher;
use App\Support\InternalReturnUrl;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassificationRuleController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly ClassificationRuleMatcher $matcher,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $listing = ListingQuery::from(
            $request,
            ['name', 'match_type', 'action_type', 'status'],
            'name',
            'asc',
            ['match_type', 'action_type', 'status'],
        );
        $query = $workspace->classificationRules()
            ->with([
                'category:id,name,parent_id',
                'category.parent:id,name',
                'counterpartAccount:id,name',
            ])
            ->getQuery();
        $listing->applySearch($query, ['name', 'pattern', 'payee_name']);

        $matchType = $listing->filter('match_type');

        if ($matchType !== null && ClassificationRuleMatchType::tryFrom($matchType) !== null) {
            $query->where('match_type', $matchType);
        }

        $actionType = $listing->filter('action_type');

        if ($actionType !== null && FinancialTransactionType::tryFrom($actionType) !== null) {
            $query->where('action_type', $actionType);
        }

        $active = $listing->booleanFilter('status');

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        if ($listing->sort === 'status') {
            $query->orderBy('is_active', $listing->direction)->orderBy('name');
        } elseif ($listing->sort === 'match_type') {
            $query->orderBy('match_type', $listing->direction)->orderBy('name');
        } elseif ($listing->sort === 'action_type') {
            $query->orderBy('action_type', $listing->direction)->orderBy('name');
        } else {
            $listing->applySort($query, [
                'name' => 'name',
                'match_type' => 'match_type',
                'action_type' => 'action_type',
                'status' => 'is_active',
            ]);
        }

        return Inertia::render('classification-rules/index', [
            'rules' => $query
                ->get()
                ->map(fn (ClassificationRule $rule): array => $this->ruleData($rule)),
            'filters' => $listing->toArray(),
            'hasRecords' => $workspace->classificationRules()->exists(),
            'matchTypeOptions' => ClassificationRuleMatchType::options(),
            'actionTypeOptions' => FinancialTransactionType::options(),
            'statusOptions' => ListingQuery::statusOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        $draft = $this->draftFromRequest($request);

        return Inertia::render('classification-rules/create', [
            'draft' => $draft,
            'matchingRule' => $this->matchingRuleFromDraft($draft),
            'matchTypeOptions' => ClassificationRuleMatchType::options(),
            'actionTypeOptions' => FinancialTransactionType::options(),
            'categoryOptions' => $this->categoryOptions(),
            'accountOptions' => $this->accountOptions(),
            'returnTo' => InternalReturnUrl::fromRequest($request, 'reconciliation.index'),
        ]);
    }

    public function store(StoreClassificationRuleRequest $request): RedirectResponse
    {
        $this->workspace()->classificationRules()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Regra cadastrada. Os próximos movimentos parecidos já podem ser classificados com ela.',
        ]);

        $returnTo = InternalReturnUrl::fromRequest($request, 'reconciliation.index');

        if ($returnTo !== null) {
            return redirect()->to($returnTo);
        }

        return to_route('classification-rules.index');
    }

    public function edit(int $rule): Response
    {
        $classificationRule = $this->findRule($rule);

        return Inertia::render('classification-rules/edit', [
            'rule' => $this->ruleData($classificationRule),
            'matchTypeOptions' => ClassificationRuleMatchType::options(),
            'actionTypeOptions' => FinancialTransactionType::options(),
            'categoryOptions' => $this->categoryOptions(),
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    public function update(
        UpdateClassificationRuleRequest $request,
        int $rule,
    ): RedirectResponse {
        $this->findRule($rule)->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Regra atualizada com sucesso.',
        ]);

        return to_route('classification-rules.index');
    }

    public function toggleStatus(int $rule): RedirectResponse
    {
        $classificationRule = $this->findRule($rule);
        $classificationRule->update([
            'is_active' => ! $classificationRule->is_active,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $classificationRule->is_active
                ? 'Regra ativada com sucesso.'
                : 'Regra desativada com sucesso.',
        ]);

        return to_route('classification-rules.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findRule(int $rule): ClassificationRule
    {
        return $this->workspace()
            ->classificationRules()
            ->with([
                'category:id,name,parent_id',
                'category.parent:id,name',
                'counterpartAccount:id,name',
            ])
            ->findOrFail($rule);
    }

    /**
     * @return array{
     *     name: string,
     *     match_type: string,
     *     pattern: string,
     *     action_type: string,
     *     payee_name: string,
     *     category_id: int|null,
     *     counterpart_account_id: int|null,
     *     source_description: string
     * }
     */
    private function draftFromRequest(Request $request): array
    {
        $description = trim($request->string('description')->toString());
        $payeeName = trim($request->string('payee_name')->toString());
        $categoryId = $request->filled('category_id') ? $request->integer('category_id') : null;
        $counterpartAccountId = $request->filled('counterpart_account_id')
            ? $request->integer('counterpart_account_id')
            : null;
        $suggestion = $description !== ''
            ? $this->matcher->suggest($description, $payeeName !== '' ? $payeeName : null)
            : null;
        $action = FinancialTransactionType::tryFrom($request->string('action_type')->toString());

        if ($action === null && $counterpartAccountId !== null) {
            $action = FinancialTransactionType::Transfer;
        }

        if ($action === null && $categoryId !== null) {
            $category = $this->workspace()->categories()->find($categoryId);
            $action = $category?->type === CategoryType::Income
                ? FinancialTransactionType::Income
                : FinancialTransactionType::Expense;
        }

        return [
            'name' => $suggestion['name'] ?? $payeeName,
            'match_type' => $request->string('match_type')->toString() !== ''
                ? $request->string('match_type')->toString()
                : ($suggestion['match_type'] ?? ClassificationRuleMatchType::Contains->value),
            'pattern' => $request->string('pattern')->toString() !== ''
                ? $request->string('pattern')->toString()
                : ($suggestion['pattern'] ?? ''),
            'action_type' => ($action ?? FinancialTransactionType::Expense)->value,
            'payee_name' => $payeeName,
            'category_id' => $action === FinancialTransactionType::Transfer ? null : $categoryId,
            'counterpart_account_id' => $action === FinancialTransactionType::Transfer
                ? $counterpartAccountId
                : null,
            'source_description' => $description,
        ];
    }

    /**
     * @param  array{
     *     name: string,
     *     match_type: string,
     *     pattern: string,
     *     action_type: string,
     *     payee_name: string,
     *     category_id: int|null,
     *     counterpart_account_id: int|null,
     *     source_description: string
     * }  $draft
     * @return array{id: int, name: string, pattern: string, match_type: string}|null
     */
    private function matchingRuleFromDraft(array $draft): ?array
    {
        $description = $draft['source_description'];

        if ($description === '') {
            return null;
        }

        $match = $this->matcher->match($this->workspace(), $description);

        if (! is_array($match)) {
            return null;
        }

        $rule = $this->workspace()->classificationRules()->find($match['rule_id']);

        if (! $rule instanceof ClassificationRule) {
            return null;
        }

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'pattern' => $rule->pattern,
            'match_type' => $rule->match_type->value,
        ];
    }

    /**
     * @return list<array{id: int, name: string, parent_id: int|null, type: string, type_label: string}>
     */
    private function categoryOptions(): array
    {
        return $this->workspace()
            ->categories()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'type'])
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'type' => $category->type->value,
                'type_label' => $category->type->label(),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function accountOptions(): array
    {
        return $this->workspace()
            ->financialAccounts()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (FinancialAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
            ])
            ->all();
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     match_type: string,
     *     match_type_label: string,
     *     match_type_help: string,
     *     pattern: string,
     *     action_type: string,
     *     action_type_label: string,
     *     payee_name: string|null,
     *     category_id: int|null,
     *     category_name: string|null,
     *     counterpart_account_id: int|null,
     *     counterpart_account_name: string|null,
     *     is_active: bool
     * }
     */
    private function ruleData(ClassificationRule $rule): array
    {
        $category = $rule->category;
        $categoryName = $category instanceof Category
            ? ($category->parent instanceof Category
                ? $category->parent->name.' / '.$category->name
                : $category->name)
            : null;
        $account = $rule->counterpartAccount;

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'match_type' => $rule->match_type->value,
            'match_type_label' => $rule->match_type->label(),
            'match_type_help' => $rule->match_type->help(),
            'pattern' => $rule->pattern,
            'action_type' => $rule->action_type->value,
            'action_type_label' => $rule->action_type->label(),
            'payee_name' => $rule->payee_name,
            'category_id' => $rule->category_id,
            'category_name' => $categoryName,
            'counterpart_account_id' => $rule->counterpart_account_id,
            'counterpart_account_name' => $account instanceof FinancialAccount
                ? $account->name
                : null,
            'is_active' => $rule->is_active,
        ];
    }
}
