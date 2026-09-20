<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActiveWorkspaceController extends Controller
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        CurrentWorkspace $currentWorkspace,
    ): RedirectResponse {
        Gate::authorize('view', $workspace);

        $request->session()->put(CurrentWorkspace::SESSION_KEY, $workspace->getKey());
        $currentWorkspace->set($workspace);

        return to_route('dashboard');
    }
}
