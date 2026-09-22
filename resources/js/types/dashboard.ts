export type DashboardMetrics = {
    current_balance: string;
    income: string;
    expenses: string;
    projected_balance: string;
    active_accounts: number;
};

export type CashFlowPoint = {
    month: string;
    income: string;
    expenses: string;
    net: string;
};

export type CategoryExpense = {
    id: number | null;
    name: string;
    amount: string;
    percentage: number;
};

export type DashboardEntry = {
    id: number;
    source: 'transaction' | 'invoice';
    type: 'income' | 'expense' | 'transfer';
    description: string;
    amount: string;
    date: string;
    status: string;
    status_label: string;
    is_overdue?: boolean;
    category: string;
    context: string | null;
};

export type DashboardPageProps = {
    currentPeriod: string;
    metrics: DashboardMetrics;
    cashFlow: CashFlowPoint[];
    categoryExpenses: CategoryExpense[];
    upcomingEntries: DashboardEntry[];
    recentEntries: DashboardEntry[];
};
