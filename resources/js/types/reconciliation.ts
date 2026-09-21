export type ReconciliationCandidate = {
    movement_id: number;
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
};

export type ReconciliationPendingEntry = {
    id: number;
    account_name: string;
    occurred_on: string;
    amount: string;
    description: string;
    memo: string | null;
    transaction_type: string | null;
    candidates: ReconciliationCandidate[];
};

export type ReconciliationHistoryItem = {
    id: number;
    account_name: string;
    bank_description: string;
    bank_occurred_on: string;
    amount: string;
    movement_description: string | null;
    movement_occurred_on: string | null;
    movement_type_label: string | null;
    reconciled_by_name: string | null;
    reconciled_at: string | null;
};
