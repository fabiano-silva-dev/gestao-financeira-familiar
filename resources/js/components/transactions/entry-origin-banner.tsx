import { Link } from '@inertiajs/react';
import {
    CreditCard,
    FileText,
    Landmark,
    PenLine,
    Plug,
    Repeat2,
} from 'lucide-react';
import { edit as editRecurrence } from '@/routes/recurrences';
import type { FinancialEntry, FinancialEntryOrigin } from '@/types';

const originIcons = {
    manual: PenLine,
    recurrence: Repeat2,
    ofx: Landmark,
    card_import: CreditCard,
    api: Plug,
} as const satisfies Record<
    FinancialEntryOrigin,
    typeof PenLine
>;

type Props = {
    entry: FinancialEntry;
};

export function EntryOriginBanner({ entry }: Props) {
    const Icon = originIcons[entry.origin] ?? FileText;

    return (
        <div className="border-primary/20 bg-primary/5 rounded-lg border p-3 text-sm">
            <p className="flex items-center gap-2 font-medium">
                <Icon className="text-primary size-4" />
                Origem: {entry.origin_label}
            </p>
            {entry.origin_source.summary !== null && (
                <p className="text-muted-foreground mt-1 text-xs">
                    {entry.origin_source.summary}
                </p>
            )}
            {entry.financial_recurrence_id !== null && (
                <p className="text-muted-foreground mt-1 text-xs">
                    Alterações aqui valem somente para esta ocorrência. Para
                    mudar as próximas,{' '}
                    <Link
                        className="text-primary font-medium underline-offset-4 hover:underline"
                        href={editRecurrence(entry.financial_recurrence_id)}
                    >
                        edite a recorrência
                    </Link>
                    .
                </p>
            )}
        </div>
    );
}
