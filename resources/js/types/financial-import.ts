export type FinancialImportAccountOption = {
    id: number;
    name: string;
    institution: string | null;
    is_active: boolean;
};

export type FinancialImportHistoryItem = {
    id: number;
    source_filename: string;
    account_name: string;
    status: 'processing' | 'completed' | 'failed';
    status_label: string;
    total_records: number;
    imported_records: number;
    duplicate_records: number;
    statement_start_on: string | null;
    statement_end_on: string | null;
    external_account_identifier: string | null;
    error_message: string | null;
    created_at: string | null;
};

export type BankStatementEntry = {
    id: number;
    account_name: string;
    occurred_on: string;
    amount: string;
    transaction_type: string | null;
    description: string;
    memo: string | null;
    is_reconciled: boolean;
};

export type CardStatementCardOption = {
    id: number;
    name: string;
    institution: string | null;
    last_four: string;
    is_active: boolean;
};

export type CardStatementImportHistoryItem = {
    id: number;
    source_filename: string;
    card_name: string;
    card_last_four: string;
    status: 'processing' | 'completed' | 'failed';
    status_label: string;
    total_records: number;
    imported_records: number;
    duplicate_records: number;
    statement_start_on: string | null;
    statement_end_on: string | null;
    reference_month: string | null;
    statement_amount: string | null;
    statement_amount_applied: boolean | null;
    source_format: string | null;
    error_message: string | null;
    created_at: string | null;
};

export type CardStatementEntry = {
    id: number;
    card_name: string;
    card_last_four: string;
    reference_month: string;
    purchased_on: string;
    description: string;
    amount: string;
    installment_number: number | null;
    total_installments: number | null;
    is_reconciled: boolean;
};
