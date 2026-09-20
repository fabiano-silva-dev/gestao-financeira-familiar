<?php

use App\Http\Controllers\ActiveWorkspaceController;
use App\Http\Controllers\FinancialAccountController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'workspace'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('contas', [FinancialAccountController::class, 'index'])
        ->name('accounts.index');
    Route::get('contas/nova', [FinancialAccountController::class, 'create'])
        ->name('accounts.create');
    Route::post('contas', [FinancialAccountController::class, 'store'])
        ->name('accounts.store');
    Route::get('contas/{account}/editar', [FinancialAccountController::class, 'edit'])
        ->whereNumber('account')
        ->name('accounts.edit');
    Route::put('contas/{account}', [FinancialAccountController::class, 'update'])
        ->whereNumber('account')
        ->name('accounts.update');
    Route::patch('contas/{account}/status', [FinancialAccountController::class, 'toggleStatus'])
        ->whereNumber('account')
        ->name('accounts.toggle-status');

    Route::post('workspaces/{workspace}/activate', ActiveWorkspaceController::class)
        ->name('workspaces.activate');
});

require __DIR__.'/settings.php';
