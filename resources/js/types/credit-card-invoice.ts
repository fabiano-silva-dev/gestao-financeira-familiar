export type CreditCardInvoiceStatus =
    | 'open'
    | 'closed'
    | 'partial'
    | 'paid'
    | 'overdue';

export type CreditCardInvoiceInstallment = {
    id: number;
    transaction_id: number;
    description: string;
    transaction_date: string;
    installment_number: number;
    total_installments: number;
    amount: string;
    competence_month: string;
    due_date: string;
    status: 'open' | 'paid' | 'cancelled';
    status_label: string;
    category_name: string | null;
    family_member_name: string | null;
};

export type CreditCardInvoicePayment = {
    id: number;
    paid_on: string;
    amount: string;
    payment_method: string;
    payment_method_label: string;
    financial_account_id: number | null;
    account_name: string;
    is_advance: boolean;
    notes: string | null;
    is_bank_reconciled: boolean;
};

export type CreditCardInvoiceStatementCandidate = {
    kind: 'installment' | 'recurrence';
    installment_id: number | null;
    recurrence_transaction_id: number | null;
    transaction_id: number;
    transaction_date: string;
    description: string;
    amount: string;
    installment_number: number;
    total_installments: number;
    score: number;
    confidence: 'high' | 'medium' | 'low';
    confidence_label: string;
    date_distance: number;
    is_suggestion: boolean;
    is_recurrence_forecast: boolean;
    amount_difference: string;
};

export type CreditCardInvoiceLinkedInstallment = {
    id: number;
    transaction_id: number;
    description: string;
    transaction_date: string;
    installment_number: number;
    total_installments: number;
};

export type CreditCardInvoiceLinkedRefund = {
    id: number;
    transaction_id: number;
    description: string;
    amount: string;
    refunded_on: string;
};

export type CreditCardInvoiceStatementEntry = {
    id: number;
    purchased_on: string;
    description: string;
    amount: string;
    installment_number: number | null;
    total_installments: number | null;
    source: 'manual' | 'import';
    is_payment: boolean;
    linked_payment: CreditCardInvoicePayment | null;
    is_reconciled: boolean;
    reconciled_by_name: string | null;
    reconciled_at: string | null;
    linked_installment: CreditCardInvoiceLinkedInstallment | null;
    linked_refund: CreditCardInvoiceLinkedRefund | null;
    candidates: CreditCardInvoiceStatementCandidate[];
};

export type CreditCardInvoice = {
    id: number;
    credit_card_id: number;
    credit_card_name: string;
    credit_card_last_four: string;
    reference_month: string;
    closing_date: string;
    due_date: string;
    calculated_amount: string;
    statement_amount: string | null;
    statement_difference: string | null;
    net_invoice_amount: string;
    refund_amount: string;
    paid_amount: string;
    outstanding_amount: string;
    credit_balance_amount: string;
    paid_at: string | null;
    status: CreditCardInvoiceStatus;
    status_label: string;
    purchase_entries_count: number;
    reconciled_purchase_entries_count: number;
    ignored_purchase_entries_count: number;
    can_close: boolean;
    can_reopen: boolean;
    can_pay: boolean;
    payment_instructions?: string | null;
    installments?: CreditCardInvoiceInstallment[];
    statement_entries?: CreditCardInvoiceStatementEntry[];
    payments?: CreditCardInvoicePayment[];
};
