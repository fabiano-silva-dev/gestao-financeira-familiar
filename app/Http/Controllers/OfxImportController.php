<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOfxImportRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\OfxImportService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OfxImportController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly OfxImportService $importService,
    ) {}

    public function index(): RedirectResponse
    {
        return to_route('imports.index');
    }

    public function store(StoreOfxImportRequest $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $account = $workspace->financialAccounts()
            ->findOrFail($request->integer('financial_account_id'));
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $result = $this->importService->import(
            $workspace,
            $account,
            $user,
            $request->file('file'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Extrato processado: %d novo(s) e %d duplicado(s) ignorado(s).',
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
}
