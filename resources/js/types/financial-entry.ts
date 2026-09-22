export type FinancialEntryType = 'income' | 'expense' | 'transfer';

export type FinancialEntryOrigin =
    | 'manual'
    | 'recurrence'
    | 'ofx'
    | 'card_import'
    | 'api';

export type FinancialEntryOriginSource = {
    kind:
        | 'manual'
        | 'recurrence'
        | 'bank_statement'
        | 'card_statement'
        | 'api';
    label: string;
    filename: string | null;
    target_name: string | null;
    invoice_month: string | null;
    summary: string | null;
};

export type FinancialEntry = {
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
    installment_count: number;
    category_id: number | null;
    category_name: string | null;
    family_member_id: number | null;
    family_member_name: string | null;
    source_account_id: number | null;
    source_account_name: string | null;
    destination_account_id: number | null;
    destination_account_name: string | null;
    payment_method: string | null;
    payment_method_label: string | null;
    payee_name: string | null;
    payment_instructions: string | null;
    due_date: string | null;
    settled_on: string | null;
    is_settled: boolean;
    status: 'planned' | 'confirmed' | 'cancelled';
    status_label: string;
    notes: string | null;
    origin: FinancialEntryOrigin;
    origin_label: string;
    origin_source: FinancialEntryOriginSource;
    financial_recurrence_id: number | null;
    recurrence_is_overridden: boolean;
};

export type FinancialEntryReferenceOption = {
    id: number;
    name: string;
    label?: string;
    type?: FinancialEntryType;
    is_active: boolean;
};
