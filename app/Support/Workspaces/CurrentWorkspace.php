<?php

namespace App\Support\Workspaces;

use App\Models\Workspace;

class CurrentWorkspace
{
    public const SESSION_KEY = 'workspace.current_id';

    private ?Workspace $workspace = null;

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }
}
