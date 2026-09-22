<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCardStatementImportRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\CardStatementImportService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CardStatementImportController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly CardStatementImportService $importService,
    ) {}

    public function index(): RedirectResponse
    {
        return to_route('imports.index');
    }

    public function store(StoreCardStatementImportRequest $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $card = $workspace->creditCards()
            ->findOrFail($request->integer('credit_card_id'));
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $result = $this->importService->import(
            $workspace,
            $card,
            $user,
            $request->file('file'),
            $request->string('reference_month')->toString(),
            $request->string('amount_sign')->toString(),
            $this->optionalPdfLayout($request->input('pdf_layout')),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Fatura processada: %d nova(s) e %d duplicada(s) ignorada(s).',
                $result->import->imported_records,
                $result->import->duplicate_records,
            ),
        ]);

        return to_route('imports.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function optionalPdfLayout(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
