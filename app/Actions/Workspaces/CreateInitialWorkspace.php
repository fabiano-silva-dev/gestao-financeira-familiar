<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class CreateInitialWorkspace
{
    public function createFor(User $user): Workspace
    {
        $name = Str::limit("Workspace de {$user->name}", 255, '');

        return $user->workspaces()->create(
            ['name' => $name],
            ['role' => 'owner'],
        );
    }
}
