<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFamilyMemberRequest;
use App\Http\Requests\UpdateFamilyMemberRequest;
use App\Models\FamilyMember;
use App\Models\Workspace;
use App\Support\Listings\ListingQuery;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FamilyMemberController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        $listing = ListingQuery::from(
            $request,
            ['name', 'status'],
            'status',
            'desc',
            ['status'],
        );
        $query = $workspace->familyMembers()->getQuery();
        $listing->applySearch($query, ['name']);

        $active = $listing->booleanFilter('status');

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        if ($listing->sort === 'status') {
            $query->orderBy('is_active', $listing->direction)->orderBy('name');
        } else {
            $listing->applySort($query, [
                'name' => 'name',
                'status' => 'is_active',
            ]);
        }

        return Inertia::render('family-members/index', [
            'members' => $query
                ->get()
                ->map(fn (FamilyMember $member): array => $this->memberData($member)),
            'filters' => $listing->toArray(),
            'hasRecords' => $workspace->familyMembers()->exists(),
            'statusOptions' => ListingQuery::statusOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('family-members/create');
    }

    public function store(StoreFamilyMemberRequest $request): RedirectResponse
    {
        $this->workspace()->familyMembers()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pessoa cadastrada com sucesso.',
        ]);

        return to_route('family-members.index');
    }

    public function edit(int $member): Response
    {
        return Inertia::render('family-members/edit', [
            'member' => $this->memberData($this->findMember($member)),
        ]);
    }

    public function update(
        UpdateFamilyMemberRequest $request,
        int $member,
    ): RedirectResponse {
        $this->findMember($member)->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pessoa atualizada com sucesso.',
        ]);

        return to_route('family-members.index');
    }

    public function toggleStatus(int $member): RedirectResponse
    {
        $familyMember = $this->findMember($member);
        $familyMember->update([
            'is_active' => ! $familyMember->is_active,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $familyMember->is_active
                ? 'Pessoa ativada com sucesso.'
                : 'Pessoa desativada com sucesso.',
        ]);

        return to_route('family-members.index');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->currentWorkspace->get();

        abort_if($workspace === null, 403);

        return $workspace;
    }

    private function findMember(int $member): FamilyMember
    {
        return $this->workspace()
            ->familyMembers()
            ->findOrFail($member);
    }

    /**
     * @return array{id: int, name: string, is_active: bool}
     */
    private function memberData(FamilyMember $member): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'is_active' => $member->is_active,
        ];
    }
}
