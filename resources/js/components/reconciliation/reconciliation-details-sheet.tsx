import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Badge } from '@/components/ui/badge';
import { formatReconciliationDate } from '@/components/reconciliation/reconciliation-row';
import type { ReconciliationPendingEntry } from '@/types';

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

type Props = {
    entry: ReconciliationPendingEntry | null;
    onClose: () => void;
};

export function ReconciliationDetailsSheet({ entry, onClose }: Props) {
    return (
        <Sheet
            open={entry !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <SheetContent className="sm:max-w-lg">
                {entry && (
                    <>
                        <SheetHeader>
                            <SheetTitle>{entry.description}</SheetTitle>
                            <SheetDescription>
                                A origem externa permanece intacta. A
                                classificação e o vínculo interno são conferidos
                                à parte.
                            </SheetDescription>
                        </SheetHeader>
                        <div className="space-y-5 overflow-y-auto px-4 pb-6">
                            <section className="space-y-2">
                                <h3 className="text-sm font-medium">
                                    Informação externa
                                </h3>
                                <dl className="text-muted-foreground grid gap-1 text-sm">
                                    <div>
                                        Data:{' '}
                                        <span className="text-foreground">
                                            {formatReconciliationDate(
                                                entry.occurred_on,
                                            )}
                                        </span>
                                    </div>
                                    <div>
                                        Valor:{' '}
                                        <span className="text-foreground tabular-nums">
                                            {currency.format(
                                                Number(entry.amount),
                                            )}
                                        </span>
                                    </div>
                                    <div>
                                        Origem:{' '}
                                        <span className="text-foreground">
                                            {entry.source_format_label}
                                        </span>
                                    </div>
                                    <div>
                                        Conta ou cartão:{' '}
                                        <span className="text-foreground">
                                            {entry.source_name}
                                        </span>
                                    </div>
                                    {entry.import_id !== null && (
                                        <div>
                                            Importação:{' '}
                                            <span className="text-foreground">
                                                #{entry.import_id}
                                                {entry.import_filename
                                                    ? ` · ${entry.import_filename}`
                                                    : ''}
                                            </span>
                                        </div>
                                    )}
                                    <div>
                                        Relação:{' '}
                                        <span className="text-foreground">
                                            {entry.relation_path}
                                        </span>
                                    </div>
                                    {entry.memo && (
                                        <div>
                                            Memo:{' '}
                                            <span className="text-foreground">
                                                {entry.memo}
                                            </span>
                                        </div>
                                    )}
                                </dl>
                            </section>

                            <section className="space-y-2">
                                <h3 className="text-sm font-medium">
                                    Informação interna
                                </h3>
                                <dl className="text-muted-foreground grid gap-1 text-sm">
                                    <div>
                                        Empresa:{' '}
                                        <span className="text-foreground">
                                            {entry.payee_name ??
                                                entry.related_payee_name ??
                                                '—'}
                                        </span>
                                    </div>
                                    <div>
                                        Categoria:{' '}
                                        <span className="text-foreground">
                                            {entry.category_name ??
                                                entry.related_category_name ??
                                                '—'}
                                        </span>
                                    </div>
                                    <div>
                                        Subcategoria:{' '}
                                        <span className="text-foreground">
                                            {entry.subcategory_name ??
                                                entry.related_subcategory_name ??
                                                '—'}
                                        </span>
                                    </div>
                                    <div>
                                        Tipo:{' '}
                                        <span className="text-foreground">
                                            {entry.related_type_label ?? '—'}
                                        </span>
                                    </div>
                                    <div>
                                        Competência:{' '}
                                        <span className="text-foreground">
                                            {entry.related_competence_date
                                                ? formatReconciliationDate(
                                                      entry.related_competence_date,
                                                  )
                                                : '—'}
                                        </span>
                                    </div>
                                    <div>
                                        Lançamento:{' '}
                                        <span className="text-foreground">
                                            {entry.related_description ?? '—'}
                                        </span>
                                    </div>
                                </dl>
                            </section>

                            {(entry.is_likely_invoice_payment ||
                                entry.is_invoice_payment) && (
                                <section className="space-y-2">
                                    <h3 className="text-sm font-medium">
                                        Pagamento de fatura
                                    </h3>
                                    <dl className="text-muted-foreground grid gap-1 text-sm">
                                        <div>
                                            Cartão:{' '}
                                            <span className="text-foreground">
                                                {entry.card_name ?? '—'}
                                            </span>
                                        </div>
                                        <div>
                                            Fatura:{' '}
                                            <span className="text-foreground">
                                                {entry.invoice_label ?? '—'}
                                            </span>
                                        </div>
                                        <div>
                                            Vencimento:{' '}
                                            <span className="text-foreground">
                                                {entry.invoice_due_date
                                                    ? formatReconciliationDate(
                                                          entry.invoice_due_date,
                                                      )
                                                    : '—'}
                                            </span>
                                        </div>
                                        <div>
                                            Total:{' '}
                                            <span className="text-foreground tabular-nums">
                                                {entry.invoice_total_amount
                                                    ? currency.format(
                                                          Number(
                                                              entry.invoice_total_amount,
                                                          ),
                                                      )
                                                    : '—'}
                                            </span>
                                        </div>
                                        <div>
                                            Já pago:{' '}
                                            <span className="text-foreground tabular-nums">
                                                {entry.invoice_paid_amount
                                                    ? currency.format(
                                                          Number(
                                                              entry.invoice_paid_amount,
                                                          ),
                                                      )
                                                    : '—'}
                                            </span>
                                        </div>
                                        <div>
                                            Saldo em aberto:{' '}
                                            <span className="text-foreground tabular-nums">
                                                {entry.invoice_outstanding_amount
                                                    ? currency.format(
                                                          Number(
                                                              entry.invoice_outstanding_amount,
                                                          ),
                                                      )
                                                    : '—'}
                                            </span>
                                        </div>
                                    </dl>
                                </section>
                            )}

                            {entry.candidates.length > 0 && (
                                <section className="space-y-2">
                                    <h3 className="text-sm font-medium">
                                        Correspondências
                                    </h3>
                                    <ul className="space-y-2">
                                        {entry.candidates.map((candidate) => (
                                            <li
                                                key={`${candidate.movement_id ?? candidate.invoice_id ?? candidate.installment_id}`}
                                                className="rounded-lg border px-3 py-2 text-sm"
                                            >
                                                <p className="font-medium">
                                                    {candidate.description}
                                                </p>
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    {candidate.type_label} ·{' '}
                                                    {formatReconciliationDate(
                                                        candidate.occurred_on,
                                                    )}{' '}
                                                    ·{' '}
                                                    {candidate.confidence_label}{' '}
                                                    ({candidate.score}%)
                                                </p>
                                                {candidate.is_suggestion && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="mt-2"
                                                    >
                                                        Sugerida
                                                    </Badge>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}

                            {entry.reconciled_at && (
                                <p className="text-muted-foreground text-xs">
                                    Conciliado em{' '}
                                    {dateTime.format(
                                        new Date(entry.reconciled_at),
                                    )}
                                    {entry.reconciled_by_name
                                        ? ` por ${entry.reconciled_by_name}`
                                        : ''}
                                </p>
                            )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
