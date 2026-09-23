import { Form, Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpDown,
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
    Search,
    Upload,
} from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

type TypeFilter = 'all' | SourceItem['kind'];
type SortColumn =
    | 'kind'
    | 'name'
    | 'reference'
    | 'import'
    | 'coverage'
    | 'items'
    | 'reconciliation'
    | 'closure';
type SortDirection = 'asc' | 'desc';

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

function formatDate(value: string | null) {
    return value ? date.format(new Date(`${value}T00:00:00Z`)) : '—';
}

function formatReference(value: string) {
    const [year, month] = value.split('-');

    return `${month}/${year}`;
}

function adjacentMonth(period: string, offset: number) {
    const [year, month] = period.split('-').map(Number);
    const target = new Date(Date.UTC(year, month - 1 + offset, 1));

    return `${target.getUTCFullYear()}-${String(target.getUTCMonth() + 1).padStart(2, '0')}`;
}

function itemKey(item: SourceItem) {
    return `${item.kind}-${item.id}`;
}

function closingPresentation(item: SourceItem) {
    if (item.status === 'closed') {
        return {
            label: 'Fechado',
            className: 'border-positive/30 bg-positive-muted text-positive',
        };
    }

    if (item.status === 'no_movement') {
        return {
            label: 'Sem movimento',
            className: 'border-positive/30 bg-positive-muted text-positive',
        };
    }

    return {
        label: 'Aberto',
        className:
            item.status === 'reconciled'
                ? 'border-warning/40 bg-warning-muted text-warning-foreground'
                : 'border-border bg-muted/50 text-muted-foreground',
    };
}

function importPresentation(item: SourceItem) {
    if (!item.has_import) {
        return {
            label: 'Não importado',
            className:
                'border-destructive/30 bg-destructive/10 text-destructive',
        };
    }

    return {
        label:
            item.imports.length > 1
                ? `${item.imports.length} arquivos`
                : 'Importado',
        className: 'border-positive/30 bg-positive-muted text-positive',
    };
}

function coveragePresentation(item: SourceItem) {
    if (item.kind === 'card' || !item.has_import) {
        return null;
    }

    return item.period_complete
        ? {
              label: 'Completo',
              className: 'border-positive/30 bg-positive-muted text-positive',
          }
        : {
              label: 'Incompleto',
              className:
                  'border-warning/40 bg-warning-muted text-warning-foreground',
          };
}

function reconciliationPresentation(item: SourceItem) {
    if (!item.has_import || item.status === 'no_movement') {
        return null;
    }

    if (item.pending_items > 0) {
        return {
            label: `${item.pending_items} ${item.pending_items === 1 ? 'pendência' : 'pendências'}`,
            className:
                'border-warning/40 bg-warning-muted text-warning-foreground',
        };
    }

    if (item.total_items === 0) {
        return {
            label: 'Sem itens',
            className: 'border-border bg-muted/50 text-muted-foreground',
        };
    }

    return {
        label: 'Conciliado',
        className: 'border-positive/30 bg-positive-muted text-positive',
    };
}

function sortValue(item: SourceItem, column: SortColumn, period: string) {
    switch (column) {
        case 'kind':
            return item.kind === 'account' ? 0 : 1;
        case 'name':
            return item.name.toLocaleLowerCase('pt-BR');
        case 'reference':
            return item.invoice?.reference_month ?? period;
        case 'import':
            return item.has_import ? 1 : 0;
        case 'coverage':
            if (item.kind === 'card') {
                return 3;
            }

            if (!item.has_import) {
                return 0;
            }

            return item.period_complete ? 2 : 1;
        case 'items':
            return item.total_items;
        case 'reconciliation':
            if (!item.has_import) {
                return 3;
            }

            if (item.pending_items > 0) {
                return 0;
            }

            return item.total_items > 0 ? 1 : 2;
        case 'closure':
            return {
                not_imported: 0,
                pending_reconciliation: 1,
                imported: 2,
                reconciled: 3,
                closed: 4,
                no_movement: 4,
            }[item.status];
    }
}

function SortHeader({
    column,
    label,
    sortColumn,
    sortDirection,
    onSort,
    align = 'left',
}: {
    column: SortColumn;
    label: string;
    sortColumn: SortColumn;
    sortDirection: SortDirection;
    onSort: (column: SortColumn) => void;
    align?: 'left' | 'right' | 'center';
}) {
    const active = column === sortColumn;

    return (
        <button
            type="button"
            onClick={() => onSort(column)}
            className={`hover:text-foreground flex w-full items-center gap-1 font-medium transition-colors ${align === 'right' ? 'justify-end' : align === 'center' ? 'justify-center' : 'justify-start'}`}
            aria-label={`Ordenar por ${label}`}
        >
            <span>{label}</span>
            <ArrowUpDown
                className={`size-3.5 ${active ? 'text-primary' : 'text-muted-foreground/60'}`}
            />
            {active && (
                <span className="sr-only">
                    {sortDirection === 'asc'
                        ? 'ordem crescente'
                        : 'ordem decrescente'}
                </span>
            )}
        </button>
    );
}

function SourceActions({
    item,
    period,
    expanded,
    onToggleDetails,
}: {
    item: SourceItem;
    period: string;
    expanded: boolean;
    onToggleDetails: () => void;
}) {
    const historyQuery =
        item.kind === 'account' ? `account=${item.id}` : `card=${item.id}`;
    const reconciliationQuery =
        item.kind === 'account'
            ? `kind=statement&account=${item.id}`
            : `kind=invoice&card=${item.id}`;
    const canMarkNoMovement =
        (item.status === 'not_imported' || item.status === 'imported') &&
        item.total_items === 0;

    return (
        <div className="flex items-center justify-end gap-0.5">
            {item.status === 'not_imported' && (
                <Button
                    asChild
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    title={
                        item.kind === 'account'
                            ? 'Importar extrato'
                            : 'Importar fatura'
                    }
                >
                    <Link href="/importacoes">
                        <Upload />
                        <span className="sr-only">
                            {item.kind === 'account'
                                ? 'Importar extrato'
                                : 'Importar fatura'}
                        </span>
                    </Link>
                </Button>
            )}

            {canMarkNoMovement && (
                <Form
                    action={`/importacoes/fechamento-mensal/${item.kind}/${item.id}/sem-movimento`}
                    method="post"
                >
                    <input type="hidden" name="period" value={period} />
                    <Button
                        type="submit"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        title={
                            item.kind === 'account'
                                ? 'Marcar conta sem movimento'
                                : 'Marcar fatura sem movimento'
                        }
                    >
                        <CircleX />
                        <span className="sr-only">
                            {item.kind === 'account'
                                ? 'Marcar conta sem movimento'
                                : 'Marcar fatura sem movimento'}
                        </span>
                    </Button>
                </Form>
            )}

            {item.status === 'pending_reconciliation' && (
                <Button
                    asChild
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    title="Conciliar"
                >
                    <Link
                        href={`/conciliacao?${reconciliationQuery}&period=${period}&view=pending`}
                    >
                        <ListChecks />
                        <span className="sr-only">Conciliar</span>
                    </Link>
                </Button>
            )}

            {item.status === 'reconciled' && (
                <Form
                    action={`/importacoes/fechamento-mensal/${item.kind}/${item.id}/fechar`}
                    method="post"
                >
                    <input type="hidden" name="period" value={period} />
                    <Button
                        type="submit"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        title="Marcar como fechado"
                    >
                        <CheckCircle2 />
                        <span className="sr-only">Marcar como fechado</span>
                    </Button>
                </Form>
            )}

            {(item.status === 'closed' || item.status === 'no_movement') && (
                <Form
                    action={`/importacoes/fechamento-mensal/${item.kind}/${item.id}/reabrir`}
                    method="delete"
                >
                    <input type="hidden" name="period" value={period} />
                    <Button
                        type="submit"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        title="Reabrir"
                    >
                        <RotateCcw />
                        <span className="sr-only">Reabrir</span>
                    </Button>
                </Form>
            )}

            <Button
                asChild
                variant="ghost"
                size="icon"
                className="size-8"
                title="Histórico"
            >
                <Link
                    href={`/importacoes/historico?${historyQuery}&period=${period}`}
                >
                    <History />
                    <span className="sr-only">Histórico</span>
                </Link>
            </Button>

            {(item.imports.length > 0 || item.closure) && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    title={expanded ? 'Ocultar detalhes' : 'Ver detalhes'}
                    aria-expanded={expanded}
                    onClick={onToggleDetails}
                >
                    <ChevronDown
                        className={`transition-transform ${expanded ? 'rotate-180' : ''}`}
                    />
                    <span className="sr-only">
                        {expanded ? 'Ocultar detalhes' : 'Ver detalhes'}
                    </span>
                </Button>
            )}
        </div>
    );
}

function SourceDetails({ item }: { item: SourceItem }) {
    return (
        <div className="space-y-2 px-3 py-3 text-xs">
            {item.kind === 'account' && item.has_import && (
                <p className="text-muted-foreground">
                    Cobertura do extrato:{' '}
                    <span className="text-foreground font-medium">
                        {formatDate(item.period_start)} a{' '}
                        {formatDate(item.period_end)}
                    </span>
                </p>
            )}

            {item.kind === 'card' && item.invoice && (
                <p className="text-muted-foreground">
                    Fatura:{' '}
                    <span className="text-foreground font-medium">
                        {item.invoice.due_date
                            ? `vencimento ${formatDate(item.invoice.due_date)}`
                            : 'sem vencimento informado'}
                        {item.invoice.amount
                            ? ` · ${currency.format(Number(item.invoice.amount))}`
                            : ''}
                    </span>
                </p>
            )}

            {item.closure && (
                <p className="text-muted-foreground">
                    Confirmado por{' '}
                    <span className="text-foreground font-medium">
                        {item.closure.closed_by ?? 'usuário'}
                    </span>
                    {item.closure.closed_at
                        ? ` em ${dateTime.format(new Date(item.closure.closed_at))}`
                        : ''}
                    .
                </p>
            )}

            {item.imports.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {item.imports.map((entry) => (
                        <div
                            key={entry.id}
                            className="bg-muted/45 rounded-md border px-2.5 py-2"
                        >
                            <span className="font-medium">
                                {entry.filename}
                            </span>
                            <span className="text-muted-foreground">
                                {' '}
                                · {entry.total_records} itens
                                {entry.start_on && entry.end_on
                                    ? ` · ${formatDate(entry.start_on)} a ${formatDate(entry.end_on)}`
                                    : ''}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </div>
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
    const [typeFilter, setTypeFilter] = useState<TypeFilter>('all');
    const [search, setSearch] = useState('');
    const [sortColumn, setSortColumn] = useState<SortColumn>('closure');
    const [sortDirection, setSortDirection] = useState<SortDirection>('asc');
    const [expandedKey, setExpandedKey] = useState<string | null>(null);

    const filterItems = [
        ['all', 'Todos', counts.all],
        ['pending', 'Pendentes', counts.pending],
        ['not_imported', 'Não importados', counts.not_imported],
        ['reconciliation', 'A conciliar', counts.reconciliation],
        ['reconciled', 'Conciliados', counts.reconciled],
        ['closed', 'Fechados', counts.closed],
    ] as const;

    const visibleItems = useMemo(() => {
        const normalizedSearch = search.trim().toLocaleLowerCase('pt-BR');

        return [...accounts, ...cards]
            .filter((item) => typeFilter === 'all' || item.kind === typeFilter)
            .filter((item) => {
                if (!normalizedSearch) {
                    return true;
                }

                return [item.name, item.subtitle]
                    .filter(Boolean)
                    .some((value) =>
                        value
                            .toLocaleLowerCase('pt-BR')
                            .includes(normalizedSearch),
                    );
            })
            .sort((left, right) => {
                const leftValue = sortValue(left, sortColumn, period);
                const rightValue = sortValue(right, sortColumn, period);
                let comparison = 0;

                if (
                    typeof leftValue === 'number' &&
                    typeof rightValue === 'number'
                ) {
                    comparison = leftValue - rightValue;
                } else {
                    comparison = String(leftValue).localeCompare(
                        String(rightValue),
                        'pt-BR',
                        { numeric: true, sensitivity: 'base' },
                    );
                }

                if (comparison === 0) {
                    comparison = left.name.localeCompare(right.name, 'pt-BR', {
                        sensitivity: 'base',
                    });
                }

                return sortDirection === 'asc' ? comparison : -comparison;
            });
    }, [
        accounts,
        cards,
        period,
        search,
        sortColumn,
        sortDirection,
        typeFilter,
    ]);

    const navigate = (nextPeriod: string) =>
        router.get(
            '/importacoes/fechamento-mensal',
            { period: nextPeriod, view },
            { preserveState: true, preserveScroll: true },
        );

    const onSort = (column: SortColumn) => {
        if (sortColumn === column) {
            setSortDirection((current) => (current === 'asc' ? 'desc' : 'asc'));

            return;
        }

        setSortColumn(column);
        setSortDirection('asc');
    };

    return (
        <>
            <Head title="Fechamento mensal de importações" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Importações · conferência por exceção
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Fechamento mensal
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Contas e cartões do workspace em uma visão compacta
                            para localizar rapidamente o que ainda precisa de
                            atenção.
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

                <div className="bg-card grid grid-cols-2 overflow-hidden rounded-lg border sm:grid-cols-4">
                    <div className="border-b p-3 sm:border-r sm:border-b-0">
                        <p className="text-muted-foreground text-xs">
                            Concluídas
                        </p>
                        <p className="mt-0.5 text-lg font-semibold tabular-nums">
                            {summary.completed}/{summary.total}
                        </p>
                    </div>
                    <div className="border-b p-3 sm:border-r sm:border-b-0">
                        <p className="text-muted-foreground text-xs">
                            Não importadas
                        </p>
                        <p className="text-destructive mt-0.5 text-lg font-semibold tabular-nums">
                            {summary.not_imported}
                        </p>
                    </div>
                    <div className="p-3 sm:border-r">
                        <p className="text-muted-foreground text-xs">
                            A conciliar
                        </p>
                        <p className="text-warning-foreground mt-0.5 text-lg font-semibold tabular-nums">
                            {summary.pending_reconciliation}
                        </p>
                    </div>
                    <div className="border-l p-3 sm:border-l-0">
                        <p className="text-muted-foreground text-xs">
                            Incompletos
                        </p>
                        <p className="text-warning-foreground mt-0.5 text-lg font-semibold tabular-nums">
                            {summary.incomplete_period}
                        </p>
                    </div>
                </div>

                <div className="flex flex-col gap-2 rounded-lg border p-2.5">
                    <div className="flex flex-wrap items-center gap-2">
                        {filterItems.map(([key, label, count]) => (
                            <Button
                                key={key}
                                variant={view === key ? 'default' : 'outline'}
                                size="sm"
                                className="h-8"
                                asChild
                            >
                                <Link
                                    href={`/importacoes/fechamento-mensal?period=${period}&view=${key}`}
                                >
                                    {label}
                                    <span className="tabular-nums">
                                        ({count})
                                    </span>
                                </Link>
                            </Button>
                        ))}

                        <div className="bg-border mx-1 hidden h-6 w-px lg:block" />

                        {(
                            [
                                ['all', 'Contas + cartões'],
                                ['account', 'Contas'],
                                ['card', 'Cartões'],
                            ] as const
                        ).map(([key, label]) => (
                            <Button
                                key={key}
                                type="button"
                                variant={
                                    typeFilter === key ? 'secondary' : 'ghost'
                                }
                                size="sm"
                                className="h-8"
                                onClick={() => setTypeFilter(key)}
                            >
                                {label}
                            </Button>
                        ))}

                        <div className="relative ml-auto w-full sm:w-64">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                            <Input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Buscar conta ou cartão…"
                                className="h-8 pl-8"
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 border-t pt-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-8"
                            asChild
                        >
                            <Link href="/importacoes">
                                <Upload />
                                Importar
                            </Link>
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-8"
                            asChild
                        >
                            <Link href="/conciliacao">
                                <ListChecks />
                                Conciliação
                            </Link>
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-8"
                            asChild
                        >
                            <Link
                                href={`/importacoes/historico?period=${period}`}
                            >
                                <History />
                                Histórico
                            </Link>
                        </Button>

                        <span className="text-muted-foreground ml-auto text-xs tabular-nums">
                            {visibleItems.length} de{' '}
                            {accounts.length + cards.length} exibidos
                        </span>
                    </div>
                </div>

                {visibleItems.length === 0 ? (
                    <div className="text-muted-foreground rounded-lg border py-12 text-center text-sm">
                        Nenhuma conta ou cartão encontrado para os filtros
                        atuais.
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-hidden rounded-lg border xl:block">
                            <div className="max-h-[calc(100vh-330px)] min-h-72 overflow-auto">
                                <table className="w-full table-fixed text-sm">
                                    <thead className="bg-muted/90 sticky top-0 z-10 border-b backdrop-blur">
                                        <tr className="text-muted-foreground text-xs">
                                            <th className="w-[7%] px-2 py-2 text-left">
                                                <SortHeader
                                                    column="kind"
                                                    label="Tipo"
                                                    sortColumn={sortColumn}
                                                    sortDirection={
                                                        sortDirection
                                                    }
                                                    onSort={onSort}
                                                />
                                            </th>
                                            <th className="w-[20%] px-2 py-2 text-left">
                                                <SortHeader
                                                    column="name"
                                                    label="Conta / cartão"
                                                    sortColumn={sortColumn}
                                                    sortDirection={
                                                        sortDirection
                                                    }
                                                    onSort={onSort}
                                                />
                                            </th>
                                            <th className="w-[10%] px-2 py-2 text-left">
                                                <SortHeader
                                                    column="reference"
                                                    label="Referência"
                                                    sortColumn={sortColumn}
                                                    sortDirection={
                                                        sortDirection
                                                    }
                                                    onSort={onSort}
                                                />
                                            </th>
                                            <th className="w-[12%] px-2 py-2 text-left">
                                                <SortHeader
                                                    column="import"
                                                    label="Importação"
                                                    sortColumn={sortColumn}
                                                    sortDirection={
                                                        sortDirection
                                                    }
                                                    onSort={onSort}
                                                />
                                            </th>
                                            <th className="w-[11%] px-2 py-2 text-left">
                                                <SortHeader
                                                    column="coverage"
                                                    label="Cobertura"
                                                    sortColumn={sortColumn}
                                                    sortDirection={
                                                        sortDirection
                                                    }
                                                    onSort={onSort}
                                                />
                                            </th>
                                            <th className="w-[7%] px-2 py-2 text-right">
                                                <SortHeader
                                                    column="items"
                                                    label="Itens"
                                                    sortColumn={sortColumn}
                                                    sortDirection={
                                                        sortDirection
                                                    }
                                                    onSort={onSort}
                                                    align="right"
                                                />
                                            </th>
                                            <th className="w-[13%] px-2 py-2 text-left">
                                                <SortHeader
                                                    column="reconciliation"
                                                    label="Conciliação"
                                                    sortColumn={sortColumn}
                                                    sortDirection={
                                                        sortDirection
                                                    }
                                                    onSort={onSort}
                                                />
                                            </th>
                                            <th className="w-[11%] px-2 py-2 text-left">
                                                <SortHeader
                                                    column="closure"
                                                    label="Fechamento"
                                                    sortColumn={sortColumn}
                                                    sortDirection={
                                                        sortDirection
                                                    }
                                                    onSort={onSort}
                                                />
                                            </th>
                                            <th className="w-[9%] px-2 py-2 text-right font-medium">
                                                Ações
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {visibleItems.map((item) => {
                                            const key = itemKey(item);
                                            const importStatus =
                                                importPresentation(item);
                                            const coverage =
                                                coveragePresentation(item);
                                            const reconciliation =
                                                reconciliationPresentation(
                                                    item,
                                                );
                                            const closing =
                                                closingPresentation(item);
                                            const expanded =
                                                expandedKey === key;

                                            return (
                                                <Fragment key={key}>
                                                    <tr className="hover:bg-muted/30 h-12">
                                                        <td className="px-2 py-1.5">
                                                            <Badge
                                                                variant="outline"
                                                                className="h-6 px-1.5 text-[11px]"
                                                            >
                                                                {item.kind ===
                                                                'account'
                                                                    ? 'Conta'
                                                                    : 'Cartão'}
                                                            </Badge>
                                                        </td>
                                                        <td className="min-w-0 px-2 py-1.5">
                                                            <p
                                                                className="truncate font-medium"
                                                                title={
                                                                    item.name
                                                                }
                                                            >
                                                                {item.name}
                                                            </p>
                                                            {item.subtitle && (
                                                                <p className="text-muted-foreground truncate text-[11px]">
                                                                    {
                                                                        item.subtitle
                                                                    }
                                                                </p>
                                                            )}
                                                        </td>
                                                        <td className="px-2 py-1.5 tabular-nums">
                                                            <p>
                                                                {formatReference(
                                                                    item.invoice
                                                                        ?.reference_month ??
                                                                        period,
                                                                )}
                                                            </p>
                                                            {item.kind ===
                                                                'card' &&
                                                                item.invoice
                                                                    ?.due_date && (
                                                                    <p className="text-muted-foreground text-[11px]">
                                                                        Venc.{' '}
                                                                        {formatDate(
                                                                            item
                                                                                .invoice
                                                                                .due_date,
                                                                        )}
                                                                    </p>
                                                                )}
                                                        </td>
                                                        <td className="px-2 py-1.5">
                                                            <Badge
                                                                variant="outline"
                                                                className={`h-6 px-1.5 text-[11px] whitespace-nowrap ${importStatus.className}`}
                                                            >
                                                                {
                                                                    importStatus.label
                                                                }
                                                            </Badge>
                                                        </td>
                                                        <td className="px-2 py-1.5">
                                                            {coverage ? (
                                                                <Badge
                                                                    variant="outline"
                                                                    className={`h-6 px-1.5 text-[11px] whitespace-nowrap ${coverage.className}`}
                                                                >
                                                                    {
                                                                        coverage.label
                                                                    }
                                                                </Badge>
                                                            ) : (
                                                                <span className="text-muted-foreground">
                                                                    —
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="px-2 py-1.5 text-right font-medium tabular-nums">
                                                            {item.has_import
                                                                ? item.total_items
                                                                : '—'}
                                                        </td>
                                                        <td className="px-2 py-1.5">
                                                            {reconciliation ? (
                                                                <Badge
                                                                    variant="outline"
                                                                    className={`h-6 px-1.5 text-[11px] whitespace-nowrap ${reconciliation.className}`}
                                                                >
                                                                    {
                                                                        reconciliation.label
                                                                    }
                                                                </Badge>
                                                            ) : (
                                                                <span className="text-muted-foreground">
                                                                    —
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="px-2 py-1.5">
                                                            <Badge
                                                                variant="outline"
                                                                className={`h-6 px-1.5 text-[11px] whitespace-nowrap ${closing.className}`}
                                                            >
                                                                {closing.label}
                                                            </Badge>
                                                        </td>
                                                        <td className="px-1 py-1">
                                                            <SourceActions
                                                                item={item}
                                                                period={period}
                                                                expanded={
                                                                    expanded
                                                                }
                                                                onToggleDetails={() =>
                                                                    setExpandedKey(
                                                                        expanded
                                                                            ? null
                                                                            : key,
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                    </tr>
                                                    {expanded && (
                                                        <tr className="bg-muted/20">
                                                            <td colSpan={9}>
                                                                <SourceDetails
                                                                    item={item}
                                                                />
                                                            </td>
                                                        </tr>
                                                    )}
                                                </Fragment>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className="space-y-2 xl:hidden">
                            {visibleItems.map((item) => {
                                const key = itemKey(item);
                                const importStatus = importPresentation(item);
                                const coverage = coveragePresentation(item);
                                const reconciliation =
                                    reconciliationPresentation(item);
                                const closing = closingPresentation(item);
                                const expanded = expandedKey === key;

                                return (
                                    <div
                                        key={key}
                                        className="bg-card rounded-lg border"
                                    >
                                        <div className="flex items-center gap-2 px-3 py-2.5">
                                            <Badge
                                                variant="outline"
                                                className="h-6 px-1.5 text-[11px]"
                                            >
                                                {item.kind === 'account'
                                                    ? 'Conta'
                                                    : 'Cartão'}
                                            </Badge>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {item.name}
                                                </p>
                                                <p className="text-muted-foreground truncate text-[11px]">
                                                    {formatReference(
                                                        item.invoice
                                                            ?.reference_month ??
                                                            period,
                                                    )}
                                                    {item.subtitle
                                                        ? ` · ${item.subtitle}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <Badge
                                                variant="outline"
                                                className={`h-6 px-1.5 text-[11px] whitespace-nowrap ${closing.className}`}
                                            >
                                                {closing.label}
                                            </Badge>
                                        </div>

                                        <div className="grid grid-cols-2 gap-x-4 gap-y-2 border-t px-3 py-2 text-xs sm:grid-cols-4">
                                            <div>
                                                <p className="text-muted-foreground">
                                                    Importação
                                                </p>
                                                <p className="mt-0.5">
                                                    {importStatus.label}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground">
                                                    Cobertura
                                                </p>
                                                <p className="mt-0.5">
                                                    {coverage?.label ?? '—'}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground">
                                                    Itens
                                                </p>
                                                <p className="mt-0.5 tabular-nums">
                                                    {item.has_import
                                                        ? item.total_items
                                                        : '—'}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground">
                                                    Conciliação
                                                </p>
                                                <p className="mt-0.5">
                                                    {reconciliation?.label ??
                                                        '—'}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="flex justify-end border-t px-2 py-1">
                                            <SourceActions
                                                item={item}
                                                period={period}
                                                expanded={expanded}
                                                onToggleDetails={() =>
                                                    setExpandedKey(
                                                        expanded ? null : key,
                                                    )
                                                }
                                            />
                                        </div>

                                        {expanded && (
                                            <div className="border-t">
                                                <SourceDetails item={item} />
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}
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
