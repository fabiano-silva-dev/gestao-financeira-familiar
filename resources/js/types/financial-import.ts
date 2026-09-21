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
