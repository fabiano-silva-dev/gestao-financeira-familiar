<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Imports\MonthlyImportClosingService;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MonthlyImportClosingController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly MonthlyImportClosingService $closingService,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $period = $this->period($request->string('period')->toString());
        $status = $request->string('status')->toString();
        $status = in_array($status, [
            'all',
            'pending',
            'not_imported',
            'to_reconcile',
            'reconciled',
            'closed',
        ], true) ? $status : 'all';
        $overview = $this->closingService->overview($workspace, $period);

        return Inertia::render('imports/closing', [
            'period' => $period->format('Y-m'),
            'filters' => ['status' => $status],
            'summary' => $overview['summary'],
            'accounts' => $this->filterSources($overview['accounts'], $status),
            'cards' => $this->filterSources($overview['cards'], $status),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['account', 'card'])],
            'source_id' => ['required', 'integer'],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'action' => ['required', Rule::in(['close', 'no_movement'])],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspace();
        $period = $this->period((string) $data['period']);
        $sourceId = (int) $data['source_id'];
        $sourceType = (string) $data['source_type'];
        $action = (string) $data['action'];

        if ($sourceType === 'account') {
            $workspace->financialAccounts()
                ->where('is_active', true)
                ->findOrFail($sourceId);
        } else {
            $workspace->creditCards()
                ->where('is_active', true)
                ->findOrFail($sourceId);
        }

        $overview = $this->closingService->overview($workspace, $period);
        $sources = $sourceType === 'account' ? $overview['accounts'] : $overview['cards'];
        $source = collect($sources)->firstWhere('id', $sourceId);

        if (! is_array($source)) {
            abort(404);
        }

        if ($action === 'close' && ! ($source['can_close'] ?? false)) {
            throw ValidationException::withMessages([
                'source_id' => 'A origem precisa estar conciliada e com o período completo antes do fechamento.',
            ]);
        }

        if ($action === 'no_movement' && ($source['status'] ?? null) !== 'not_imported') {
            throw ValidationException::withMessages([
                'source_id' => 'Só é possível confirmar sem movimento quando ainda não existe importação para o período.',
            ]);
        }

        $keys = $sourceType === 'account'
            ? ['financial_account_id' => $sourceId, 'credit_card_id' => null]
            : ['financial_account_id' => null, 'credit_card_id' => $sourceId];

        $workspace->financialPeriodClosures()->updateOrCreate(
            [
                ...$keys,
                'reference_month' => $period->toDateString(),
            ],
            [
                'status' => $action === 'no_movement' ? 'no_movement' : 'closed',
                'closed_by' => $user->id,
                'closed_at' => now(),
            ],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $action === 'no_movement'
                ? 'Origem confirmada sem movimento para o período selecionado.'
                : 'Origem marcada como fechada para o período selecionado.',
        ]);

        return to_route('imports.closing.index', ['period' => $period->format('Y-m')]);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();
        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function period(string $period): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            return CarbonImmutable::now()->startOfMonth();
        }

        return CarbonImmutable::parse($period.'-01')->startOfMonth();
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @return list<array<string, mixed>>
     */
    private function filterSources(array $sources, string $status): array
    {
        if ($status === 'all') {
            return $sources;
        }

        return collect($sources)
            ->filter(function (array $source) use ($status): bool {
                return match ($status) {
                    'pending' => ! in_array($source['status'], ['closed', 'no_movement'], true),
                    'not_imported' => $source['status'] === 'not_imported',
                    'to_reconcile' => $source['status'] === 'pending_reconciliation',
                    'reconciled' => $source['status'] === 'reconciled',
                    'closed' => in_array($source['status'], ['closed', 'no_movement'], true),
                    default => true,
                };
            })
            ->values()
            ->all();
    }
}
