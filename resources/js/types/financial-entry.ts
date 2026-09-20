export type FinancialEntryType = 'income' | 'expense';

export type FinancialInstallment = {
    id: number;
    type: FinancialEntryType;
    type_label: string;
    transaction_date: string;
    competence_date: string;
    description: string;
    amount: string;
    financial_account_id: number | null;
    financial_account_name: string | null;
    credit_card_id: number | null;
    credit_card_name: string | null;
    payment_method: string;
    payment_method_label: string;
    due_date: string | null;
    settled_on: string | null;
    is_settled: boolean;
    parent_transaction_id: number | null;
    installment_number: number | null;
    installment_count: number | null;
    status: 'planned' | 'confirmed' | 'cancelled';
    status_label: string;
};

export type FinancialEntry = FinancialInstallment & {
    category_id: number | null;
    category_name: string | null;
    family_member_id: number | null;
    family_member_name: string | null;
    payee_name: string | null;
    payment_instructions: string | null;
    is_installment_purchase: boolean;
    installments: FinancialInstallment[];
    notes: string | null;
};

export type FinancialEntryReferenceOption = {
    id: number;
    name: string;
    label?: string;
    is_active: boolean;
};
