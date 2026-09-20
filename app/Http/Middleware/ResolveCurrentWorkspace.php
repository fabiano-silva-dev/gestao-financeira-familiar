<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentWorkspace
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $workspaceId = $request->session()->get(CurrentWorkspace::SESSION_KEY);

        $workspace = $workspaceId
            ? $user->workspaces()->whereKey($workspaceId)->first()
            : null;

        $workspace ??= $user->workspaces()
            ->orderBy('workspaces.id')
            ->first();

        abort_if($workspace === null, 403, 'Nenhum workspace disponível para este usuário.');

        $request->session()->put(CurrentWorkspace::SESSION_KEY, $workspace->getKey());
        $this->currentWorkspace->set($workspace);

        return $next($request);
    }
}
