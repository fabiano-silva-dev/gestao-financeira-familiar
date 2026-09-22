export type PaymentDashboardDetail = {
    label: string;
    value: string;
};

export type PaymentDashboardChild = {
    id: string;
    description: string;
    amount: string | null;
    date: string | null;
    meta: string | null;
};

export type PaymentDashboardItem = {
    id: string;
    source: 'transaction' | 'invoice' | 'card_payment' | 'installment';
    description: string;
    amount: string;
    date: string;
    status_label: string;
    is_overdue: boolean;
    context: string | null;
    details: PaymentDashboardDetail[];
    children: PaymentDashboardChild[];
};

export type PaymentDashboardMetrics = {
    paid: string;
    payable: string;
    received: string;
    receivable: string;
    projected_balance: string;
};

export type PaymentDashboardUpcomingGroup = {
    key: 'overdue' | 'today' | 'next_7_days' | 'rest_month';
    label: string;
    count: number;
    amount: string;
    item_ids: string[];
};

export type PaymentDashboardPageProps = {
    currentPeriod: string;
    metrics: PaymentDashboardMetrics;
    payable: PaymentDashboardItem[];
    paid: PaymentDashboardItem[];
    receivable: PaymentDashboardItem[];
    received: PaymentDashboardItem[];
    upcoming: PaymentDashboardUpcomingGroup[];
};
