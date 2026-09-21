export type FinancialAccount = {
    id: number;
    name: string;
    institution: string | null;
    type: string;
    type_label: string;
    opening_balance: string;
    opening_balance_date: string | null;
    current_balance: string;
    is_active: boolean;
};

export type FinancialAccountSummary = {
    total_balance: string;
    active_accounts: number;
};

export type FinancialAccountPanoramaSummary = {
    inflows: string;
    outflows: string;
    movement_count: number;
};

export type FinancialAccountMovementOverview = {
    id: number;
    occurred_on: string;
    description: string;
    amount: string;
    type: string;
    type_label: string;
    is_reconciled: boolean;
    transaction_id: number | null;
    transaction_type: string | null;
    transaction_type_label: string | null;
    category_name: string | null;
    family_member_name: string | null;
    counterparty_account_name: string | null;
    credit_card_name: string | null;
};

export type FinancialAccountTypeOption = {
    value: string;
    label: string;
};
