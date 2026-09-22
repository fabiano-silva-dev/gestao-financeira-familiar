import { Form, Head, Link, router } from '@inertiajs/react';
import {
    CalendarCheck2,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    CircleX,
    FileText,
    History,
    ListChecks,
    RotateCcw,
    Upload,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type ImportDetail = {
    id: number;
    filename: string;
    imported_at: string | null;
    start_on: string | null;
    end_on: string | null;
    total_records: number;
    processing_summary: {
        categorized_automatically?: number;
        automatically_reconciled?: number;
        remaining_exceptions?: number;
    } | null;
};

type Closure = {
    status: string;
    closed_at: string | null;
    closed_by: string | null;
    reopened_at: string | null;
    reopened_by: string | null;
};

type SourceItem = {
    id: number;
    kind: 'account' | 'card';
    name: string;
    institution: string | null;
    subtitle: string;
    status:
        | 'not_imported'
        | 'imported'
        | 'pending_reconciliation'
        | 'reconciled'
        | 'closed'
        | 'no_movement';
    has_import: boolean;
    period_complete: boolean;
    period_start: string | null;
    period_end: string | null;
    total_items: number;
    reconciled_items: number;
    pending_items: number;
    invoice: {
        reference_month: string;
        due_date: string | null;
        amount: string | null;
    } | null;
    imports: ImportDetail[];
    closure: Closure | null;
};

type Props = {
    period: string;
    view: string;
    summary: {
        total: number;
        completed: number;
        not_imported: number;
        pending_reconciliation: number;
        incomplete_period: number;
    };
    accounts: SourceItem[];
    cards: SourceItem[];
    counts: {
        all: number;
        pending: number;
        not_imported: number;
        reconciliation: number;
        reconciled: number;
        closed: number;
    };
};

const date = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const monthLabel = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

function formatDate(value: string | null) {
    return value ? date.format(new Date(`${value}T00:00:00Z`)) : '—';
}

function adjacentMonth(period: string, offset: number) {
    const [year, month] = period.split('-').map(Number);
    const target = new Date(Date.UTC(year, month - 1 + offset, 1));

    return `${target.getUTCFullYear()}-${String(target.getUTCMonth() + 1).padStart(2, '0')}`;
}

function statusPresentation(status: SourceItem['status']) {
    switch (status) {
        case 'closed':
            return {
                label: 'Fechado',
                Icon: CheckCircle2,
                className: 'border-positive/30 bg-positive-muted text-positive',
            };
        case 'no_movement':
            return {
                label: 'Sem movimento',
                Icon: CheckCircle2,
                className: 'border-positive/30 bg-positive-muted text-positive',
            };
        case 'reconciled':
            return {
                label: 'Conciliado',
                Icon: CheckCircle2,
                className: 'border-positive/30 bg-positive-muted text-positive',
            };
        case 'pending_reconciliation':
            return {
                label: 'Pendente de conciliação',
                Icon: CircleAlert,
                className:
                    'border-warning/40 bg-warning-muted text-warning-foreground',
            };
        case 'imported':
            return {
                label: 'Importado',
                Icon: FileText,
                className:
                    'border-warning/40 bg-warning-muted text-warning-foreground',
            };
        default:
            return {
                label: 'Não importado',
                Icon: CircleX,
                className:
                    'border-destructive/30 bg-destructive/10 text-destructive',
            };
    }
}

function SourceCard({ item, period }: { item: SourceItem; period: string }) {
    const status = statusPresentation(item.status);
    const StatusIcon = status.Icon;
    const historyQuery =
        item.kind === 'account' ? `account=${item.id}` : `card=${item.id}`;
    const reconciliationQuery =
        item.kind === 'account'
            ? `kind=statement&account=${item.id}`
            : `kind=invoice&card=${item.id}`;

    return (
        <Card>
            <CardContent className="pt-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0 space-y-3">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h3 className="text-base font-semibold">
                                    {item.name}
                                </h3>
                                <Badge
                                    variant="outline"
                                    className={status.className}
                                >
                                    <StatusIcon />
                                    {status.label}
                                </Badge>
                                {item.kind === 'account' &&
                                    item.has_import &&
                                    !item.period_complete && (
                                        <Badge
                                            variant="outline"
                                            className="border-warning/40 bg-warning-muted text-warning-foreground"
                                        >
                                            <CircleAlert />
                                            Período incompleto
                                        </Badge>
                                    )}
                            </div>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {[item.institution, item.subtitle]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>

                        {item.kind === 'account' ? (
                            item.has_import ? (
                                <div className="text-sm">
                                    <p>
                                        Cobertura:{' '}
                                        <span className="font-medium">
                                            {formatDate(item.period_start)} a{' '}
                                            {formatDate(item.period_end)}
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground mt-1 tabular-nums">
                                        {item.total_items} movimentos ·{' '}
                                        {item.reconciled_items} tratados ·{' '}
                                        {item.pending_items} pendentes
                                    </p>
                                </div>
                            ) : (
                                <p className="text-destructive text-sm font-medium">
                                    Extrato não importado para o período.
                                </p>
                            )
                        ) : item.has_import ? (
                            <div className="text-sm">
                                <p>
                                    Fatura {period}
                                    {item.invoice?.due_date
                                        ? ` · vencimento ${formatDate(item.invoice.due_date)}`
                                        : ''}
                                    {item.invoice?.amount
                                        ? ` · ${currency.format(Number(item.invoice.amount))}`
                                        : ''}
                                </p>
                                <p className="text-muted-foreground mt-1 tabular-nums">
                                    {item.total_items} itens ·{' '}
                                    {item.reconciled_items} tratados ·{' '}
                                    {item.pending_items} pendentes
                                </p>
                            </div>
                        ) : (
                            <p className="text-destructive text-sm font-medium">
                                Fatura não importada para o ciclo.
                            </p>
                        )}

                        {item.closure && (
                            <p className="text-muted-foreground text-xs">
                                Confirmado por{' '}
                                {item.closure.closed_by ?? 'usuário'}
                                {item.closure.closed_at
                                    ? ` em ${dateTime.format(new Date(item.closure.closed_at))}`
                                    : ''}
                                .
                            </p>
                        )}
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {item.status === 'not_imported' && (
                            <>
                                <Button asChild size="sm">
                                    <Link href="/importacoes">
                                        <Upload />
                                        {item.kind === 'account'
                                            ? 'Importar extrato'
                                            : 'Importar fatura'}
                                    </Link>
                                </Button>
                                <Form
                                    action={`/importacoes/fechamento-mensal/${item.kind}/${item.id}/sem-movimento`}
                                    method="post"
                                >
                                    <input
                                        type="hidden"
                                        name="period"
                                        value={period}
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                    >
                                        Sem movimento
                                    </Button>
                                </Form>
                            </>
                        )}
                        {item.status === 'pending_reconciliation' && (
                            <Button asChild size="sm">
                                <Link
                                    href={`/conciliacao?${reconciliationQuery}&period=${period}&view=pending`}
                                >
                                    <ListChecks />
                                    Conciliar
                                </Link>
                            </Button>
                        )}
                        {(item.status === 'reconciled' ||
                            item.status === 'imported') && (
                            <Form
                                action={`/importacoes/fechamento-mensal/${item.kind}/${item.id}/fechar`}
                                method="post"
                            >
                                <input
                                    type="hidden"
                                    name="period"
                                    value={period}
                                />
                                <Button type="submit" size="sm">
                                    <CheckCircle2 />
                                    Marcar como fechado
                                </Button>
                            </Form>
                        )}
                        {(item.status === 'closed' ||
                            item.status === 'no_movement') && (
                            <Form
                                action={`/importacoes/fechamento-mensal/${item.kind}/${item.id}/reabrir`}
                                method="delete"
                            >
                                <input
                                    type="hidden"
                                    name="period"
                                    value={period}
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                >
                                    <RotateCcw />
                                    Reabrir
                                </Button>
                            </Form>
                        )}
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={`/importacoes/historico?${historyQuery}&period=${period}`}
                            >
                                <History />
                                Histórico
                            </Link>
                        </Button>
                    </div>
                </div>

                {item.imports.length > 0 && (
                    <details className="mt-4 border-t pt-4">
                        <summary className="text-muted-foreground hover:text-foreground flex cursor-pointer list-none items-center gap-2 text-sm font-medium">
                            <ChevronDown className="size-4" />
                            {item.imports.length}{' '}
                            {item.imports.length === 1
                                ? 'importação relacionada'
                                : 'importações relacionadas'}
                        </summary>
                        <div className="mt-3 space-y-3">
                            {item.imports.map((entry) => (
                                <div
                                    key={entry.id}
                                    className="bg-muted/45 rounded-lg p-3 text-sm"
                                >
                                    <p className="font-medium">
                                        {entry.filename}
                                    </p>
                                    <p className="text-muted-foreground mt-1">
                                        {entry.imported_at
                                            ? `Importado em ${dateTime.format(new Date(entry.imported_at))}`
                                            : 'Importação registrada'}
                                        {entry.start_on && entry.end_on
                                            ? ` · ${formatDate(entry.start_on)} a ${formatDate(entry.end_on)}`
                                            : ''}
                                        {' · '}
                                        {entry.total_records} itens
                                    </p>
                                    {entry.processing_summary && (
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {entry.processing_summary
                                                .automatically_reconciled ??
                                                0}{' '}
                                            conciliados automaticamente ·{' '}
                                            {entry.processing_summary
                                                .categorized_automatically ??
                                                0}{' '}
                                            categorizados ·{' '}
                                            {entry.processing_summary
                                                .remaining_exceptions ?? 0}{' '}
                                            exceções restantes
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </details>
                )}
            </CardContent>
        </Card>
    );
}

export default function MonthlyClosing({
    period,
    view,
    summary,
    accounts,
    cards,
    counts,
}: Props) {
    const filterItems = [
        ['all', 'Todos', counts.all],
        ['pending', 'Pendentes', counts.pending],
        ['not_imported', 'Não importados', counts.not_imported],
        ['reconciliation', 'A conciliar', counts.reconciliation],
        ['reconciled', 'Conciliados', counts.reconciled],
        ['closed', 'Fechados', counts.closed],
    ] as const;
    const periodDate = new Date(`${period}-01T00:00:00Z`);

    const navigate = (nextPeriod: string) =>
        router.get(
            '/importacoes/fechamento-mensal',
            { period: nextPeriod, view },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <>
            <Head title="Fechamento mensal de importações" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Importações · conferência por exceção
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Fechamento mensal
                        </h1>
                        <p className="text-muted-foreground mt-1 max-w-3xl text-sm">
                            Checklist formado pelas contas e cartões ativos do
                            workspace. Nenhuma origem desaparece por falta de
                            arquivo.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Mês anterior"
                            onClick={() => navigate(adjacentMonth(period, -1))}
                        >
                            <ChevronLeft />
                        </Button>
                        <Input
                            type="month"
                            value={period}
                            onChange={(event) => navigate(event.target.value)}
                            className="w-44"
                            aria-label="Selecionar mês"
                        />
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Mês seguinte"
                            onClick={() => navigate(adjacentMonth(period, 1))}
                        >
                            <ChevronRight />
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Concluídas</CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {summary.completed} de {summary.total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Não importadas</CardDescription>
                            <CardTitle className="text-destructive text-2xl tabular-nums">
                                {summary.not_imported}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>A conciliar</CardDescription>
                            <CardTitle className="text-warning-foreground text-2xl tabular-nums">
                                {summary.pending_reconciliation}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>
                                Período incompleto
                            </CardDescription>
                            <CardTitle className="text-warning-foreground text-2xl tabular-nums">
                                {summary.incomplete_period}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </div>

                <div className="flex flex-wrap gap-2">
                    {filterItems.map(([key, label, count]) => (
                        <Button
                            key={key}
                            variant={view === key ? 'default' : 'outline'}
                            size="sm"
                            asChild
                        >
                            <Link
                                href={`/importacoes/fechamento-mensal?period=${period}&view=${key}`}
                            >
                                {label}
                                <span className="tabular-nums">({count})</span>
                            </Link>
                        </Button>
                    ))}
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/importacoes">
                            <Upload />
                            Importar arquivo
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/conciliacao">
                            <ListChecks />
                            Conciliação
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/importacoes/historico?period=${period}`}>
                            <History />
                            Histórico de arquivos
                        </Link>
                    </Button>
                </div>

                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <CalendarCheck2 className="text-primary size-5" />
                        <div>
                            <h2 className="text-lg font-semibold">
                                Contas financeiras
                            </h2>
                            <p className="text-muted-foreground text-sm capitalize">
                                {monthLabel.format(periodDate)}
                            </p>
                        </div>
                    </div>
                    {accounts.length === 0 ? (
                        <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">
                            Nenhuma conta neste filtro.
                        </p>
                    ) : (
                        accounts.map((item) => (
                            <SourceCard
                                key={`account-${item.id}`}
                                item={item}
                                period={period}
                            />
                        ))
                    )}
                </section>

                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <CalendarCheck2 className="text-primary size-5" />
                        <div>
                            <h2 className="text-lg font-semibold">Cartões</h2>
                            <p className="text-muted-foreground text-sm capitalize">
                                Faturas de {monthLabel.format(periodDate)}
                            </p>
                        </div>
                    </div>
                    {cards.length === 0 ? (
                        <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">
                            Nenhum cartão neste filtro.
                        </p>
                    ) : (
                        cards.map((item) => (
                            <SourceCard
                                key={`card-${item.id}`}
                                item={item}
                                period={period}
                            />
                        ))
                    )}
                </section>
            </div>
        </>
    );
}

MonthlyClosing.layout = {
    breadcrumbs: [
        {
            title: 'Importações',
            href: '/importacoes',
        },
        {
            title: 'Fechamento mensal',
            href: '/importacoes/fechamento-mensal',
        },
    ],
};
