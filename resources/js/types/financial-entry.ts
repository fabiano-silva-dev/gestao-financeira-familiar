export type FinancialEntryType = 'income' | 'expense';

export type FinancialEntry = {
    id: number;
    type: FinancialEntryType;
    type_label: string;
    transaction_date: string;
    description: string;
    amount: string;
    financial_account_id: number | null;
    financial_account_name: string | null;
    credit_card_id: number | null;
    credit_card_name: string | null;
    installment_count: number;
    category_id: number | null;
    category_name: string | null;
    family_member_id: number | null;
    family_member_name: string | null;
    payment_method: string;
    payment_method_label: string;
    payee_name: string | null;
    payment_instructions: string | null;
    due_date: string | null;
    status: 'planned' | 'confirmed' | 'cancelled';
    status_label: string;
    notes: string | null;
};

export type FinancialEntryReferenceOption = {
    id: number;
    name: string;
    label?: string;
    is_active: boolean;
};
