export type Transfer = {
    id: number;
    transaction_date: string;
    description: string;
    amount: string;
    source_account_id: number;
    source_account_name: string;
    destination_account_id: number;
    destination_account_name: string;
    status: 'planned' | 'confirmed' | 'cancelled';
    status_label: string;
    notes: string | null;
};

export type TransferAccountOption = {
    id: number;
    name: string;
    is_active: boolean;
};
