export type CreditCard = {
    id: number;
    name: string;
    institution: string | null;
    last_four: string;
    holder_id: number | null;
    holder_name: string | null;
    credit_limit: string;
    closing_day: number;
    due_day: number;
    payment_account_id: number | null;
    payment_account_name: string | null;
    invoice_payment_method: string;
    invoice_payment_method_label: string;
    payment_instructions: string | null;
    is_active: boolean;
};

export type CreditCardReferenceOption = {
    id: number;
    name: string;
    is_active: boolean;
};
