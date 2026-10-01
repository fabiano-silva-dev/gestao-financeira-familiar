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
    const href = entry.origin_source.href;
    const originBody = (
        <>
            <p className="flex items-center gap-2 font-medium">
                <Icon className="text-primary size-4" />
                Origem: {entry.origin_label}
            </p>
            {entry.origin_source.summary !== null && (
                <p className="text-muted-foreground mt-1 text-xs">
                    {entry.origin_source.summary}
                </p>
            )}
        </>
    );

    return (
        <div className="border-primary/20 bg-primary/5 rounded-lg border text-sm">
            {href !== null ? (
                <Link
                    href={href}
                    className="hover:bg-primary/10 focus-visible:ring-ring block rounded-lg p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                    {originBody}
                </Link>
            ) : (
                <div className="p-3">{originBody}</div>
            )}
            {entry.financial_recurrence_id !== null &&
                (entry.recurrence_was_removed ? (
                    <p className="text-muted-foreground px-3 pb-3 text-xs">
                        A recorrência que gerou este lançamento já foi
                        excluída. A exclusão aqui vale somente para esta
                        ocorrência.
                    </p>
                ) : (
                    <p className="text-muted-foreground px-3 pb-3 text-xs">
                        Alterações aqui valem somente para esta ocorrência.
                        Para mudar as próximas,{' '}
                        <Link
                            className="text-primary font-medium underline-offset-4 hover:underline"
                            href={editRecurrence(entry.financial_recurrence_id)}
                        >
                            edite a recorrência
                        </Link>
                        .
                    </p>
                ))}
        </div>
    );
}
