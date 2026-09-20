<?php

use App\Http\Controllers\ActiveWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'workspace'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::post('workspaces/{workspace}/activate', ActiveWorkspaceController::class)
        ->name('workspaces.activate');
});

require __DIR__.'/settings.php';
