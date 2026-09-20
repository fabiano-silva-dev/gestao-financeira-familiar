<?php

use App\Http\Controllers\ActiveWorkspaceController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CreditCardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FamilyMemberController;
use App\Http\Controllers\FinancialAccountController;
use App\Http\Controllers\FinancialTransactionController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'workspace'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

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

    Route::get('pessoas', [FamilyMemberController::class, 'index'])
        ->name('family-members.index');
    Route::get('pessoas/nova', [FamilyMemberController::class, 'create'])
        ->name('family-members.create');
    Route::post('pessoas', [FamilyMemberController::class, 'store'])
        ->name('family-members.store');
    Route::get('pessoas/{member}/editar', [FamilyMemberController::class, 'edit'])
        ->whereNumber('member')
        ->name('family-members.edit');
    Route::put('pessoas/{member}', [FamilyMemberController::class, 'update'])
        ->whereNumber('member')
        ->name('family-members.update');
    Route::patch('pessoas/{member}/status', [FamilyMemberController::class, 'toggleStatus'])
        ->whereNumber('member')
        ->name('family-members.toggle-status');

    Route::get('categorias', [CategoryController::class, 'index'])
        ->name('categories.index');
    Route::get('categorias/nova', [CategoryController::class, 'create'])
        ->name('categories.create');
    Route::post('categorias', [CategoryController::class, 'store'])
        ->name('categories.store');
    Route::get('categorias/{category}/editar', [CategoryController::class, 'edit'])
        ->whereNumber('category')
        ->name('categories.edit');
    Route::put('categorias/{category}', [CategoryController::class, 'update'])
        ->whereNumber('category')
        ->name('categories.update');
    Route::patch('categorias/{category}/status', [CategoryController::class, 'toggleStatus'])
        ->whereNumber('category')
        ->name('categories.toggle-status');

    Route::get('cartoes', [CreditCardController::class, 'index'])
        ->name('credit-cards.index');
    Route::get('cartoes/novo', [CreditCardController::class, 'create'])
        ->name('credit-cards.create');
    Route::post('cartoes', [CreditCardController::class, 'store'])
        ->name('credit-cards.store');
    Route::get('cartoes/{card}/editar', [CreditCardController::class, 'edit'])
        ->whereNumber('card')
        ->name('credit-cards.edit');
    Route::put('cartoes/{card}', [CreditCardController::class, 'update'])
        ->whereNumber('card')
        ->name('credit-cards.update');
    Route::patch('cartoes/{card}/status', [CreditCardController::class, 'toggleStatus'])
        ->whereNumber('card')
        ->name('credit-cards.toggle-status');

    Route::get('lancamentos', [FinancialTransactionController::class, 'index'])
        ->name('transactions.index');
    Route::get('lancamentos/nova-despesa', [FinancialTransactionController::class, 'createExpense'])
        ->name('transactions.create-expense');
    Route::get('lancamentos/nova-receita', [FinancialTransactionController::class, 'createIncome'])
        ->name('transactions.create-income');
    Route::post('lancamentos', [FinancialTransactionController::class, 'store'])
        ->name('transactions.store');
    Route::get('lancamentos/{entry}/editar', [FinancialTransactionController::class, 'edit'])
        ->whereNumber('entry')
        ->name('transactions.edit');
    Route::put('lancamentos/{entry}', [FinancialTransactionController::class, 'update'])
        ->whereNumber('entry')
        ->name('transactions.update');
    Route::patch('lancamentos/{entry}/status', [FinancialTransactionController::class, 'advanceStatus'])
        ->whereNumber('entry')
        ->name('transactions.advance-status');

    Route::get('transferencias', [TransferController::class, 'index'])
        ->name('transfers.index');
    Route::get('transferencias/nova', [TransferController::class, 'create'])
        ->name('transfers.create');
    Route::post('transferencias', [TransferController::class, 'store'])
        ->name('transfers.store');
    Route::get('transferencias/{transfer}/editar', [TransferController::class, 'edit'])
        ->whereNumber('transfer')
        ->name('transfers.edit');
    Route::put('transferencias/{transfer}', [TransferController::class, 'update'])
        ->whereNumber('transfer')
        ->name('transfers.update');
    Route::patch('transferencias/{transfer}/status', [TransferController::class, 'advanceStatus'])
        ->whereNumber('transfer')
        ->name('transfers.advance-status');

    Route::post('workspaces/{workspace}/activate', ActiveWorkspaceController::class)
        ->name('workspaces.activate');
});

require __DIR__.'/settings.php';
