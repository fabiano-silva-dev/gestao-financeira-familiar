import { ReconciliationRow } from '@/components/reconciliation/reconciliation-row';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatReconciliationDate } from '@/lib/reconciliation';
import type {
    ClassificationRulePrompt,
    ListingQueryState,
    ReconciliationAccountOption,
    ReconciliationCardOption,
    ReconciliationCategoryOption,
    ReconciliationPendingEntry,
} from '@/types';

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
    query: ListingQueryState;
    categoryOptions: ReconciliationCategoryOption[];
    cardOptions: ReconciliationCardOption[];
    counterpartAccountOptions: ReconciliationAccountOption[];
    onClose: () => void;
    onAskCreateRule: (prompt: ClassificationRulePrompt) => void;
};

export function ReconciliationDialog({
    entry,
    query,
    categoryOptions,
    cardOptions,
    counterpartAccountOptions,
    onClose,
    onAskCreateRule,
}: Props) {
    const status = entry?.is_ignored
        ? 'Ignorado'
        : entry?.is_reconciled
          ? 'Conciliado'
          : 'Pendente';

    return (
        <Dialog
            open={entry !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-6xl">
                {entry && (
                    <>
                        <DialogHeader className="pr-8">
                            <div className="flex flex-wrap items-center gap-2">
                                <DialogTitle>{entry.description}</DialogTitle>
                                <Badge
                                    variant={
                                        entry.is_reconciled
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {status}
                                </Badge>
                            </div>
                            <DialogDescription>
                                Informações completas da origem, do vínculo
                                financeiro e das ações disponíveis para esta linha.
                            </DialogDescription>
                        </DialogHeader>

                        <section className="bg-muted/35 grid gap-3 rounded-lg border p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <p className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                                    Data
                                </p>
                                <p>
                                    {formatReconciliationDate(entry.occurred_on)}
                                </p>
                            </div>
                            <div>
                                <p className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                                    Valor
                                </p>
                                <p className="font-semibold tabular-nums">
                                    {currency.format(Number(entry.amount))}
                                </p>
                            </div>
                            <div>
                                <p className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                                    Conta / Cartão
                                </p>
                                <p>{entry.source_name}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                                    Origem
                                </p>
                                <p>{entry.source_format_label}</p>
                            </div>
                            <div className="sm:col-span-2">
                                <p className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                                    Arquivo / Importação
                                </p>
                                <p className="break-words">
                                    {entry.import_id
                                        ? `#${entry.import_id}${entry.import_filename ? ` · ${entry.import_filename}` : ''}`
                                        : '—'}
                                </p>
                            </div>
                            <div className="sm:col-span-2">
                                <p className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                                    Caminho financeiro
                                </p>
                                <p>{entry.relation_path}</p>
                            </div>
                            {entry.memo && (
                                <div className="sm:col-span-2 lg:col-span-4">
                                    <p className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                                        Histórico / Memo
                                    </p>
                                    <p className="whitespace-pre-wrap">
                                        {entry.memo}
                                    </p>
                                </div>
                            )}
                            {entry.reconciled_at && (
                                <div className="sm:col-span-2">
                                    <p className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                                        Conciliação
                                    </p>
                                    <p>
                                        {entry.reconciled_by_name
                                            ? `${entry.reconciled_by_name} · `
                                            : ''}
                                        {dateTime.format(
                                            new Date(entry.reconciled_at),
                                        )}
                                    </p>
                                </div>
                            )}
                        </section>

                        <section>
                            <div className="mb-2">
                                <h3 className="font-medium">
                                    Vínculo e ações
                                </h3>
                                <p className="text-muted-foreground text-xs">
                                    Aqui ficam os recursos de conciliação,
                                    ajuste, ignorar e desfazer da linha.
                                </p>
                            </div>
                            <div className="overflow-hidden rounded-lg border">
                                <ReconciliationRow
                                    entry={entry}
                                    query={query}
                                    categoryOptions={categoryOptions}
                                    cardOptions={cardOptions}
                                    counterpartAccountOptions={
                                        counterpartAccountOptions
                                    }
                                    onOpenDetails={() => {}}
                                    onAskCreateRule={onAskCreateRule}
                                    showDetailsAction={false}
                                />
                            </div>
                        </section>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
