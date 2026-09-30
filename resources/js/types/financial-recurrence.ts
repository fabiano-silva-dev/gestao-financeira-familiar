export type FinancialRecurrenceType = 'income' | 'expense';
export type RecurrenceFrequency = 'weekly' | 'monthly' | 'yearly';

export type FinancialRecurrence = {
    id: number;
    type: FinancialRecurrenceType;
    type_label: string;
    description: string;
    amount: string;
    financial_account_id: number | null;
    financial_account_name: string | null;
    credit_card_id: number | null;
    credit_card_name: string | null;
    category_id: number | null;
    category_name: string | null;
    family_member_id: number | null;
    family_member_name: string | null;
    payment_method: string;
    payment_method_label: string;
    payee_name: string | null;
    payment_instructions: string | null;
    frequency: RecurrenceFrequency;
    frequency_label: string;
    interval: number;
    schedule_label: string;
    starts_on: string;
    generation_started_on: string;
    ends_on: string | null;
    next_occurrence: string | null;
    is_active: boolean;
    generated_transactions_count: number;
    notes: string | null;
};

export type FinancialRecurrenceOccurrence = {
    id: number;
    occurrence_date: string;
    transaction_date: string;
    due_date: string | null;
    settled_on: string | null;
    amount: string;
    status: string;
    status_label: string;
    account_name: string | null;
    is_overridden: boolean;
    is_reconciled: boolean;
};

export type RecurrenceProjectionPoint = {
    month: string;
    income: string;
    expenses: string;
    net: string;
};

export type RecurrenceOption = {
    value: string;
    label: string;
};
