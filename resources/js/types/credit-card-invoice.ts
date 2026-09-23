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
    account_name: string;
    notes: string | null;
};

export type CreditCardInvoiceStatementCandidate = {
    installment_id: number;
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
};

export type CreditCardInvoiceLinkedInstallment = {
    id: number;
    transaction_id: number;
    description: string;
    transaction_date: string;
    installment_number: number;
    total_installments: number;
};

export type CreditCardInvoiceStatementEntry = {
    id: number;
    purchased_on: string;
    description: string;
    amount: string;
    installment_number: number | null;
    total_installments: number | null;
    source: 'manual' | 'import';
    is_reconciled: boolean;
    reconciled_by_name: string | null;
    reconciled_at: string | null;
    linked_installment: CreditCardInvoiceLinkedInstallment | null;
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
    paid_at: string | null;
    status: CreditCardInvoiceStatus;
    status_label: string;
    can_close: boolean;
    can_pay: boolean;
    payment_instructions?: string | null;
    installments?: CreditCardInvoiceInstallment[];
    statement_entries?: CreditCardInvoiceStatementEntry[];
    payments?: CreditCardInvoicePayment[];
};
