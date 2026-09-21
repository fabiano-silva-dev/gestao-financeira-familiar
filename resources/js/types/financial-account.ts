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

export type FinancialAccountTypeOption = {
    value: string;
    label: string;
};
