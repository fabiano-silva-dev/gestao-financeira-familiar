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
    name: string;
    amount: string;
    percentage: number;
};

export type DashboardEntry = {
    id: number;
    type: 'income' | 'expense' | 'transfer';
    description: string;
    amount: string;
    date: string;
    status: 'planned' | 'confirmed';
    status_label: string;
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
