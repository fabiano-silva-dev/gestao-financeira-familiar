export type ReconciliationKind = 'statement' | 'invoice';

export type ReconciliationView =
    | 'all'
    | 'pending'
    | 'suggestions'
    | 'duplicates'
    | 'uncategorized'
    | 'transfers'
    | 'reconciled';

export type ReconciliationCandidate = {
    movement_id?: number | null;
    installment_id?: number;
    invoice_id?: number | null;
    invoice_payment_id?: number | null;
    transaction_id?: number | null;
    is_refund?: boolean;
    is_planned?: boolean;
    remaining_refundable_amount?: string | null;
    occurred_on: string;
    description: string;
    amount: string;
    type: string;
    type_label: string;
    score: number;
    confidence: 'high' | 'medium' | 'low';
    confidence_label: string;
    date_distance: number;
    is_suggestion: boolean;
    is_invoice_payment?: boolean;
    card_name?: string | null;
    card_last_four?: string | null;
    invoice_label?: string | null;
    invoice_due_date?: string | null;
    invoice_total_amount?: string | null;
    invoice_paid_amount?: string | null;
    invoice_outstanding_amount?: string | null;
    invoice_status?: string | null;
    invoice_status_label?: string | null;
    related_transaction_id?: number | null;
    related_description?: string | null;
    related_type?: string | null;
    related_type_label?: string | null;
    related_account_name?: string | null;
    related_counterpart_account_name?: string | null;
    related_competence_date?: string | null;
    related_payee_name?: string | null;
    related_category_id?: number | null;
    related_category_name?: string | null;
    related_parent_category_id?: number | null;
    related_parent_category_name?: string | null;
    related_subcategory_id?: number | null;
    related_subcategory_name?: string | null;
    related_is_transfer?: boolean;
};

export type ReconciliationPendingEntry = {
    id: number;
    kind: ReconciliationKind;
    kind_label: string;
    source_format_label: string;
    source_name: string;
    account_name: string;
    card_name: string | null;
    financial_account_id: number | null;
    credit_card_id: number | null;
    import_id: number | null;
    import_filename: string | null;
    invoice_id: number | null;
    invoice_label: string | null;
    invoice_payment_id: number | null;
    card_last_four: string | null;
    invoice_due_date: string | null;
    invoice_total_amount: string | null;
    invoice_paid_amount: string | null;
    invoice_outstanding_amount: string | null;
    invoice_status: string | null;
    invoice_status_label: string | null;
    is_invoice_payment: boolean;
    occurred_on: string;
    amount: string;
    description: string;
    memo: string | null;
    transaction_type: string | null;
    installment_label: string | null;
    relation_path: string;
    payee_name: string | null;
    category_id: number | null;
    category_name: string | null;
    parent_category_id: number | null;
    parent_category_name: string | null;
    subcategory_id: number | null;
    subcategory_name: string | null;
    related_transaction_id: number | null;
    related_description: string | null;
    related_type: string | null;
    related_type_label: string | null;
    related_account_name: string | null;
    related_counterpart_account_name: string | null;
    related_competence_date: string | null;
    related_payee_name: string | null;
    related_category_id: number | null;
    related_category_name: string | null;
    related_parent_category_id: number | null;
    related_parent_category_name: string | null;
    related_subcategory_id: number | null;
    related_subcategory_name: string | null;
    related_is_transfer: boolean;
    matcher_rule_id: number | null;
    matcher_payee_name: string | null;
    matcher_action_type: 'income' | 'expense' | 'transfer' | null;
    matcher_counterpart_account_id: number | null;
    matcher_category_id: number | null;
    matcher_category_name: string | null;
    matcher_parent_category_id: number | null;
    matcher_parent_category_name: string | null;
    matcher_subcategory_id: number | null;
    matcher_subcategory_name: string | null;
    has_suggestion: boolean;
    is_likely_transfer: boolean;
    is_likely_invoice_payment: boolean;
    is_likely_refund: boolean;
    is_uncategorized: boolean;
    is_possible_duplicate: boolean;
    is_reconciled: boolean;
    is_ignored: boolean;
    suggestion_confidence: 'high' | 'medium' | 'low' | null;
    suggestion_confidence_label: string | null;
    suggestion_score: number | null;
    suggestion_description: string | null;
    reconciled_by_name: string | null;
    reconciled_at: string | null;
    candidates: ReconciliationCandidate[];
};

export type ReconciliationHistoryItem = {
    id: number;
    kind: ReconciliationKind;
    invoice_id: number | null;
    account_name: string;
    source_name: string;
    source_format_label: string;
    bank_description: string;
    bank_occurred_on: string;
    amount: string;
    movement_description: string | null;
    movement_occurred_on: string | null;
    movement_type_label: string | null;
    reconciled_by_name: string | null;
    reconciled_at: string | null;
};

export type ReconciliationFilters = {
    kind: 'all' | ReconciliationKind;
    account: number | null;
    card: number | null;
    import: number | null;
    period: string | null;
    from: string | null;
    to: string | null;
    view: ReconciliationView;
    q?: string;
    sort?: string;
    direction?: 'asc' | 'desc';
};

export type ReconciliationViewCounts = {
    all: number;
    pending: number;
    suggestions: number;
    duplicates: number;
    uncategorized: number;
    transfers: number;
    reconciled: number;
};

export type ReconciliationAccountOption = {
    id: number;
    name: string;
};

export type ReconciliationCardOption = {
    id: number;
    name: string;
    last_four: string;
};

export type ReconciliationImportOption = {
    id: number;
    filename: string | null;
    label: string;
    kind: ReconciliationKind;
    kind_label: string;
    target: string;
    period: string | null;
    account_id: number | null;
    card_id: number | null;
};

export type ReconciliationCategoryOption = {
    id: number;
    name: string;
    parent_id: number | null;
    type: 'income' | 'expense';
    type_label: string;
};
