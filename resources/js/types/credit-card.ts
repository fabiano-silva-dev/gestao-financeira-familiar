export type CreditCard = {
    id: number;
    name: string;
    institution: string | null;
    last_four: string;
    holder_id: number | null;
    holder_name: string | null;
    credit_limit: string;
    used_limit: string;
    available_limit: string;
    closing_day: number;
    due_day: number;
    payment_account_id: number | null;
    payment_account_name: string | null;
    invoice_payment_method: string;
    invoice_payment_method_label: string;
    payment_instructions: string | null;
    is_active: boolean;
};

export type CreditCardSummary = {
    total_limit: string;
    used_limit: string;
    available_limit: string;
};

export type CreditCardInvoiceOverview = {
    id: number;
    reference_month: string;
    closing_date: string;
    due_date: string;
    total_amount: string;
    outstanding_amount: string;
    status: 'open' | 'closed' | 'partial' | 'paid' | 'overdue';
    status_label: string;
};

export type CreditCardTransactionOverview = {
    id: number;
    transaction_date: string;
    description: string;
    amount: string;
    status: 'planned' | 'confirmed' | 'cancelled';
    status_label: string;
    category_name: string | null;
    family_member_name: string | null;
    installment_count: number;
    open_installment_count: number;
    next_due_date: string | null;
};

export type CreditCardReferenceOption = {
    id: number;
    name: string;
    is_active: boolean;
};
