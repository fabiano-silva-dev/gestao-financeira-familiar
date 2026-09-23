export type FinancialImportAccountOption = {
    id: number;
    name: string;
    institution: string | null;
    institution_key: string | null;
    agency: string | null;
    account_number: string | null;
    is_active: boolean;
};

export type FinancialImportHistoryItem = {
    id: number;
    source_filename: string;
    account_name: string;
    status: 'processing' | 'needs_confirmation' | 'completed' | 'failed';
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
    institution_key: string | null;
    last_four: string;
    holder_name: string | null;
    payment_account_name: string | null;
    payment_account_institution: string | null;
    payment_account_agency: string | null;
    payment_account_number: string | null;
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

export type UnifiedImportKind = 'statement' | 'invoice' | 'document';

export type FinancialDocumentDetection = {
    document_type:
        | 'bank_statement'
        | 'payment_account_statement'
        | 'credit_card_statement'
        | 'proof'
        | 'unknown';
    institution: string | null;
    confidence: number;
    format: string;
    parser_key: string | null;
    identifier_type: string | null;
    identifier_value: string | null;
    reference_month: string | null;
    holder_name: string | null;
    metadata: Record<string, unknown>;
    confirmed_by_user?: boolean;
};

export type PdfLayoutOption = {
    value: string;
    label: string;
    kind: UnifiedImportKind;
};

export type FinancialImportProcessingSummary = {
    items_imported: number;
    new_items: number;
    automatically_reconciled: number;
    matched_existing: number;
    new_transactions_created: number;
    transfers_identified: number;
    invoice_payments_identified: number;
    refunds_identified: number;
    categorized_automatically: number;
    pending_categorization: number;
    pending_confirmation: number;
    duplicates_ignored: number;
    remaining_exceptions: number;
    processed_at: string;
};

export type UnifiedImportHistoryItem = {
    id: number;
    financial_account_id: number | null;
    credit_card_id: number | null;
    can_reassign: boolean;
    kind: UnifiedImportKind;
    kind_label: string;
    source_filename: string;
    target_name: string | null;
    status: 'processing' | 'needs_confirmation' | 'completed' | 'failed';
    status_label: string;
    total_records: number;
    imported_records: number;
    duplicate_records: number;
    statement_start_on: string | null;
    statement_end_on: string | null;
    statement_amount: string | null;
    statement_amount_applied: boolean | null;
    reference_month: string | null;
    source_format: string | null;
    processing_summary: FinancialImportProcessingSummary | null;
    autodetection: FinancialDocumentDetection | null;
    missing_fields: string[];
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
