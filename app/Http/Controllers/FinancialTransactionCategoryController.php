<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFinancialTransactionCategoryRequest;
use App\Models\Category;
use App\Models\Workspace;
use App\Services\Finance\FinancialEntryService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class FinancialTransactionCategoryController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly FinancialEntryService $entryService,
    ) {}

    public function update(
        UpdateFinancialTransactionCategoryRequest $request,
        int $entry,
    ): RedirectResponse {
        $workspace = $this->workspace();
        $financialEntry = $workspace->financialTransactions()->findOrFail($entry);
        $category = $this->category($workspace, $request->validated('category_id'));

        $this->entryService->updateCategory($financialEntry, $category);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Categoria atualizada com sucesso.',
        ]);

        return back();
    }

    public function bulkUpdate(
        UpdateFinancialTransactionCategoryRequest $request,
    ): RedirectResponse {
        $workspace = $this->workspace();
        $validated = $request->validated();
        $category = $this->category($workspace, $validated['category_id'] ?? null);

        $updated = $this->entryService->updateCategoryInBulk(
            $workspace,
            $validated['entry_ids'],
            $category,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $updated === 1
                ? 'Categoria de 1 lançamento atualizada com sucesso.'
                : "Categoria de {$updated} lançamentos atualizada com sucesso.",
        ]);

        return back();
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function category(Workspace $workspace, mixed $categoryId): ?Category
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        return $workspace->categories()->findOrFail((int) $categoryId);
    }
}
