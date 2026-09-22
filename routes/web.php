<?php

use App\Http\Controllers\ActiveWorkspaceController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\CardStatementImportController;
use App\Http\Controllers\CardStatementReconciliationController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClassificationRuleController;
use App\Http\Controllers\CreditCardController;
use App\Http\Controllers\CreditCardInvoiceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FamilyMemberController;
use App\Http\Controllers\FinancialAccountController;
use App\Http\Controllers\FinancialImportController;
use App\Http\Controllers\FinancialRecurrenceController;
use App\Http\Controllers\FinancialTransactionController;
use App\Http\Controllers\OfxImportController;
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
    Route::get('contas/{account}', [FinancialAccountController::class, 'show'])
        ->whereNumber('account')
        ->name('accounts.show');
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

    Route::get('regras', [ClassificationRuleController::class, 'index'])
        ->name('classification-rules.index');
    Route::get('regras/nova', [ClassificationRuleController::class, 'create'])
        ->name('classification-rules.create');
    Route::post('regras', [ClassificationRuleController::class, 'store'])
        ->name('classification-rules.store');
    Route::get('regras/{rule}/editar', [ClassificationRuleController::class, 'edit'])
        ->whereNumber('rule')
        ->name('classification-rules.edit');
    Route::put('regras/{rule}', [ClassificationRuleController::class, 'update'])
        ->whereNumber('rule')
        ->name('classification-rules.update');
    Route::patch('regras/{rule}/status', [ClassificationRuleController::class, 'toggleStatus'])
        ->whereNumber('rule')
        ->name('classification-rules.toggle-status');

    Route::get('cartoes', [CreditCardController::class, 'index'])
        ->name('credit-cards.index');
    Route::get('cartoes/novo', [CreditCardController::class, 'create'])
        ->name('credit-cards.create');
    Route::post('cartoes', [CreditCardController::class, 'store'])
        ->name('credit-cards.store');
    Route::get('cartoes/{card}', [CreditCardController::class, 'show'])
        ->whereNumber('card')
        ->name('credit-cards.show');
    Route::get('cartoes/{card}/editar', [CreditCardController::class, 'edit'])
        ->whereNumber('card')
        ->name('credit-cards.edit');
    Route::put('cartoes/{card}', [CreditCardController::class, 'update'])
        ->whereNumber('card')
        ->name('credit-cards.update');
    Route::patch('cartoes/{card}/status', [CreditCardController::class, 'toggleStatus'])
        ->whereNumber('card')
        ->name('credit-cards.toggle-status');

    Route::get('faturas', [CreditCardInvoiceController::class, 'index'])
        ->name('credit-card-invoices.index');
    Route::get('faturas/{invoice}', [CreditCardInvoiceController::class, 'show'])
        ->whereNumber('invoice')
        ->name('credit-card-invoices.show');
    Route::patch('faturas/{invoice}/fechar', [CreditCardInvoiceController::class, 'close'])
        ->whereNumber('invoice')
        ->name('credit-card-invoices.close');
    Route::post('faturas/{invoice}/pagamentos', [CreditCardInvoiceController::class, 'pay'])
        ->whereNumber('invoice')
        ->name('credit-card-invoices.pay');
    Route::post(
        'faturas/{invoice}/pagamentos/{payment}/vincular',
        [CreditCardInvoiceController::class, 'linkPayment'],
    )
        ->whereNumber('invoice')
        ->whereNumber('payment')
        ->name('credit-card-invoices.payments.link');
    Route::post(
        'faturas/{invoice}/linhas/{entry}/conciliar',
        [CardStatementReconciliationController::class, 'store'],
    )
        ->whereNumber('invoice')
        ->whereNumber('entry')
        ->name('credit-card-invoices.statement-entries.reconcile');
    Route::delete(
        'faturas/{invoice}/linhas/{entry}/conciliar',
        [CardStatementReconciliationController::class, 'destroy'],
    )
        ->whereNumber('invoice')
        ->whereNumber('entry')
        ->name('credit-card-invoices.statement-entries.reconciliation.destroy');
    Route::patch(
        'faturas/{invoice}/linhas/{entry}/ignorar',
        [CardStatementReconciliationController::class, 'ignore'],
    )
        ->whereNumber('invoice')
        ->whereNumber('entry')
        ->name('credit-card-invoices.statement-entries.ignore');
    Route::patch(
        'faturas/{invoice}/linhas/{entry}/classificacao',
        [CardStatementReconciliationController::class, 'classify'],
    )
        ->whereNumber('invoice')
        ->whereNumber('entry')
        ->name('credit-card-invoices.statement-entries.classify');
    Route::post(
        'faturas/{invoice}/linhas/{entry}/lancamento',
        [CardStatementReconciliationController::class, 'create'],
    )
        ->whereNumber('invoice')
        ->whereNumber('entry')
        ->name('credit-card-invoices.statement-entries.create');

    Route::get('lancamentos', [FinancialTransactionController::class, 'index'])
        ->name('transactions.index');
    Route::get('lancamentos/nova-despesa', [FinancialTransactionController::class, 'createExpense'])
        ->name('transactions.create-expense');
    Route::get('lancamentos/nova-receita', [FinancialTransactionController::class, 'createIncome'])
        ->name('transactions.create-income');
    Route::get('lancamentos/nova-transferencia', [FinancialTransactionController::class, 'createTransfer'])
        ->name('transactions.create-transfer');
    Route::post('lancamentos', [FinancialTransactionController::class, 'store'])
        ->name('transactions.store');
    Route::get('lancamentos/{entry}/editar', [FinancialTransactionController::class, 'edit'])
        ->whereNumber('entry')
        ->name('transactions.edit');
    Route::put('lancamentos/{entry}', [FinancialTransactionController::class, 'update'])
        ->whereNumber('entry')
        ->name('transactions.update');
    Route::post('lancamentos/{entry}/reembolsos', [FinancialTransactionController::class, 'refund'])
        ->whereNumber('entry')
        ->name('transactions.refunds.store');
    Route::patch('lancamentos/{entry}/status', [FinancialTransactionController::class, 'advanceStatus'])
        ->whereNumber('entry')
        ->name('transactions.advance-status');
    Route::patch('lancamentos/{entry}/liquidacao', [FinancialTransactionController::class, 'toggleSettlement'])
        ->whereNumber('entry')
        ->name('transactions.toggle-settlement');

    Route::get('recorrencias', [FinancialRecurrenceController::class, 'index'])
        ->name('recurrences.index');
    Route::get('recorrencias/nova', [FinancialRecurrenceController::class, 'create'])
        ->name('recurrences.create');
    Route::post('recorrencias', [FinancialRecurrenceController::class, 'store'])
        ->name('recurrences.store');
    Route::get('recorrencias/{recurrence}/editar', [FinancialRecurrenceController::class, 'edit'])
        ->whereNumber('recurrence')
        ->name('recurrences.edit');
    Route::put('recorrencias/{recurrence}', [FinancialRecurrenceController::class, 'update'])
        ->whereNumber('recurrence')
        ->name('recurrences.update');
    Route::patch('recorrencias/{recurrence}/status', [FinancialRecurrenceController::class, 'toggleStatus'])
        ->whereNumber('recurrence')
        ->name('recurrences.toggle-status');

    Route::get('transferencias', fn () => to_route('transactions.index'))
        ->name('transfers.index');
    Route::get('transferencias/nova', fn () => to_route('transactions.create-transfer'))
        ->name('transfers.create');
    Route::post('transferencias', [TransferController::class, 'store'])
        ->name('transfers.store');
    Route::get('transferencias/{transfer}/editar', function (int $transfer) {
        return to_route('transactions.edit', $transfer);
    })
        ->whereNumber('transfer')
        ->name('transfers.edit');
    Route::put('transferencias/{transfer}', [TransferController::class, 'update'])
        ->whereNumber('transfer')
        ->name('transfers.update');
    Route::patch('transferencias/{transfer}/status', [TransferController::class, 'advanceStatus'])
        ->whereNumber('transfer')
        ->name('transfers.advance-status');

    Route::get('importacoes', [FinancialImportController::class, 'index'])
        ->name('imports.index');
    Route::post('importacoes', [FinancialImportController::class, 'store'])
        ->name('imports.store');
    Route::post('importacoes/{import}/resolver', [FinancialImportController::class, 'resolve'])
        ->whereNumber('import')
        ->name('imports.resolve');
    Route::get('importacoes/extrato', fn () => to_route('imports.index'))
        ->name('imports.ofx.index');
    Route::post('importacoes/extrato', [OfxImportController::class, 'store'])
        ->name('imports.ofx.store');
    Route::redirect('importacoes/ofx', '/importacoes');
    Route::get('importacoes/faturas', fn () => to_route('imports.index'))
        ->name('imports.card-statements.index');
    Route::post('importacoes/faturas', [CardStatementImportController::class, 'store'])
        ->name('imports.card-statements.store');

    Route::get('conciliacao', [BankReconciliationController::class, 'index'])
        ->name('reconciliation.index');
    Route::post('conciliacao/importacoes/{import}/reprocessar', [BankReconciliationController::class, 'reprocess'])
        ->whereNumber('import')
        ->name('reconciliation.reprocess');
    Route::post('conciliacao/{entry}', [BankReconciliationController::class, 'store'])
        ->whereNumber('entry')
        ->name('reconciliation.store');
    Route::delete('conciliacao/{entry}', [BankReconciliationController::class, 'destroy'])
        ->whereNumber('entry')
        ->name('reconciliation.destroy');
    Route::patch('conciliacao/{entry}/ignorar', [BankReconciliationController::class, 'ignore'])
        ->whereNumber('entry')
        ->name('reconciliation.ignore');
    Route::patch('conciliacao/{entry}/classificacao', [BankReconciliationController::class, 'classify'])
        ->whereNumber('entry')
        ->name('reconciliation.classify');
    Route::post('conciliacao/{entry}/lancamento', [BankReconciliationController::class, 'create'])
        ->whereNumber('entry')
        ->name('reconciliation.create');
    Route::post('conciliacao/{entry}/transferencia', [BankReconciliationController::class, 'transfer'])
        ->whereNumber('entry')
        ->name('reconciliation.transfer');
    Route::post('conciliacao/{entry}/pagamento-fatura', [BankReconciliationController::class, 'invoicePayment'])
        ->whereNumber('entry')
        ->name('reconciliation.invoice-payment');
    Route::post('conciliacao/{entry}/pagamento-cartao', [BankReconciliationController::class, 'cardPayment'])
        ->whereNumber('entry')
        ->name('reconciliation.card-payment');
    Route::post('conciliacao/{entry}/reembolso', [BankReconciliationController::class, 'refund'])
        ->whereNumber('entry')
        ->name('reconciliation.refund');

    Route::post('workspaces/{workspace}/activate', ActiveWorkspaceController::class)
        ->name('workspaces.activate');
});

require __DIR__.'/settings.php';
