import type {
    ReconciliationCandidate,
    ReconciliationPendingEntry,
} from '@/types';

const date = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

export function formatReconciliationDate(value: string) {
    return date.format(new Date(`${value}T00:00:00Z`));
}

export function reconciliationEntryElementId(
    kind: string,
    id: number,
): string {
    return `entry-${kind}-${id}`;
}

export function candidateMatchId(
    entry: ReconciliationPendingEntry,
    candidate: ReconciliationCandidate,
): string {
    if (entry.kind === 'statement') {
        if (candidate.movement_id) {
            return String(candidate.movement_id);
        }

        if (candidate.invoice_id) {
            return `invoice:${candidate.invoice_id}`;
        }

        if (candidate.is_refund && candidate.transaction_id) {
            return `refund:${candidate.transaction_id}`;
        }

        return '';
    }

    return String(candidate.installment_id ?? '');
}
