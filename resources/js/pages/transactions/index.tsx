import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowRight,
    CircleArrowDown,
    CircleArrowUp,
    Plus,
    ReceiptText,
    Repeat2,
} from 'lucide-react';
import { MonthSelector } from '@/components/dashboard/month-selector';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { sortListing } from '@/lib/listing';
import {
    createExpense,
    createIncome,
    createTransfer,
    edit,
    index,
} from '@/routes/transactions';
import type {
    FinancialEntry,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    entries: FinancialEntry[];
    filters: ListingQueryState;
    importScope: {
        id: number;
        filename: string;
    } | null;
    hasRecords: boolean;
    typeOptions: ListingFilterOption[];
    statusOptions: ListingFilterOption[];
    settlementOptions: ListingFilterOption[];
    categoryOptions: ListingFilterOption[];
    accountOptions: ListingFilterOption[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const date = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

function formatDate(value: string) {
    return date.format(new Date(`${value}T00:00:00Z`));
}

function typeIcon(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return ArrowLeftRight;
    }

    return entry.type === 'expense' ? CircleArrowDown : CircleArrowUp;
}

function typeIconClass(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return 'text-primary';
    }

    return entry.type === 'expense' ? 'text-destructive' : 'text-positive';
}

function amountClass(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return 'text-foreground';
    }

    return entry.type === 'expense' ? 'text-destructive' : 'text-positive';
}

function amountPrefix(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return '';
    }

    return entry.type === 'expense' ? '− ' : '+ ';
}

function accountLabel(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        if (entry.source_account_name && entry.destination_account_name) {
            return `${entry.source_account_name} → ${entry.destination_account_name}`;
        }

        return 'Contas não informadas';
    }

    return (
        entry.credit_card_name ??
        entry.financial_account_name ??
        'Sem conta'
    );
}

function categoryLabel(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return 'Transferência';
    }

    return entry.category_name ?? 'Sem categoria';
}

function settlementLabel(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return entry.status === 'confirmed'
            ? 'Saldo atualizado'
            : 'Sem efeito no saldo';
    }

    if (entry.payment_method === 'credit_card') {
        return entry.installment_count > 1
            ? `${entry.installment_count} parcelas`
            : 'Via fatura';
    }

    if (entry.is_settled) {
        const settledOn = entry.settled_on
            ? ` em ${formatDate(entry.settled_on)}`
            : '';

        return `${entry.type === 'expense' ? 'Pago' : 'Recebido'}${settledOn}`;
    }

    if (entry.due_date) {
        return `Pendente · vence ${formatDate(entry.due_date)}`;
    }

    return 'Pendente';
}

function entrySubtitle(entry: FinancialEntry) {
    const parts = [
        entry.type_label,
        accountLabel(entry),
        formatDate(entry.transaction_date),
        entry.origin_label,
    ];

    if (entry.payment_method_label && entry.type !== 'transfer') {
        parts.push(entry.payment_method_label);
    }

    if (entry.family_member_name) {
        parts.push(entry.family_member_name);
    }

    if (
        entry.type !== 'transfer' &&
        entry.competence_date !== entry.transaction_date
    ) {
        parts.push(`competência ${formatDate(entry.competence_date)}`);
    }

    if (entry.payee_name && entry.payee_name !== entry.description) {
        parts.push(entry.payee_name);
    }

    return parts.join(' · ');
}

export default function TransactionsIndex() {
    const {
        entries,
        filters,
        importScope,
        hasRecords,
        typeOptions,
        statusOptions,
        settlementOptions,
        categoryOptions,
        accountOptions,
        workspace,
    } = usePage<Props>().props;
    const listUrl = index.url();
    const onSort = (column: string) =>
        sortListing(
            listUrl,
            filters,
            column,
            column === 'description' || column === 'category' ? 'asc' : 'desc',
        );

    return (
        <>
            <Head title="Lançamentos" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="min-w-0">
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Movimentação
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Lançamentos
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {importScope ? (
                                <>
                                    Lançamentos originados do arquivo{' '}
                                    <span className="text-foreground font-medium">
                                        {importScope.filename}
                                    </span>
                                    .{' '}
                                    <Link
                                        href={index()}
                                        className="text-primary font-medium"
                                    >
                                        Ver todos
                                    </Link>
                                </>
                            ) : (
                                <>
                                    Lista das receitas, despesas e
                                    transferências de{' '}
                                    <span className="text-foreground font-medium">
                                        {workspace.current?.name}
                                    </span>
                                    . Abra uma linha para ver e ajustar o
                                    lançamento.
                                </>
                            )}
                        </p>
                    </div>

                    <div className="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <Button variant="outline" asChild>
                            <Link href={createIncome()}>
                                <CircleArrowUp />
                                Nova receita
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={createTransfer()}>
                                <ArrowLeftRight />
                                Nova transferência
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={createExpense()}>
                                <Plus />
                                Nova despesa
                            </Link>
                        </Button>
                    </div>
                </div>

                {!hasRecords ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <ReceiptText className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhum lançamento cadastrado
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Registre a primeira receita, despesa ou
                                    transferência para iniciar o acompanhamento
                                    financeiro.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={createExpense()}>
                                    Cadastrar primeira despesa
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <ListingToolbar
                            url={listUrl}
                            query={filters}
                            searchPlaceholder="Buscar descrição ou estabelecimento…"
                            selects={[
                                {
                                    key: 'type',
                                    label: 'Tipo',
                                    value: filters.type,
                                    options: typeOptions,
                                    allLabel: 'Todos',
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    value: filters.status,
                                    options: statusOptions,
                                    allLabel: 'Todos',
                                },
                                {
                                    key: 'settlement',
                                    label: 'Liquidação',
                                    value: filters.settlement,
                                    options: settlementOptions,
                                    allLabel: 'Todas',
                                },
                                {
                                    key: 'category',
                                    label: 'Categoria',
                                    value: filters.category,
                                    options: categoryOptions,
                                    allLabel: 'Todas',
                                },
                                {
                                    key: 'account',
                                    label: 'Conta',
                                    value: filters.account,
                                    options: accountOptions,
                                    allLabel: 'Todas',
                                },
                            ]}
                            end={
                                <div className="grid gap-1.5">
                                    <Label>Mês</Label>
                                    <MonthSelector
                                        currentPeriod={`${String(filters.period).slice(0, 7)}-01`}
                                        url={listUrl}
                                        query={filters}
                                    />
                                </div>
                            }
                        />

                        {entries.length === 0 ? (
                            <ListingEmpty />
                        ) : (
                            <Card className="gap-0 overflow-hidden py-0">
                                <div className="text-muted-foreground hidden grid-cols-[minmax(12rem,1.6fr)_minmax(7rem,0.7fr)_minmax(8rem,0.8fr)_minmax(8rem,0.8fr)_minmax(7rem,0.7fr)_1.25rem] gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid">
                                    <SortableColumn
                                        column="description"
                                        label="Lançamento"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="date"
                                        label="Data"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="category"
                                        label="Categoria"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="status"
                                        label="Situação"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="amount"
                                        label="Valor"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                        align="right"
                                    />
                                    <span className="sr-only">Abrir</span>
                                </div>
                                <div className="divide-y">
                                    {entries.map((entry) => {
                                        const TypeIcon = typeIcon(entry);

                                        return (
                                            <Link
                                                key={entry.id}
                                                href={edit(entry.id)}
                                                className={`hover:bg-muted/40 focus-visible:ring-ring group grid grid-cols-1 gap-2 px-4 py-3 transition-colors focus-visible:ring-2 focus-visible:outline-none md:grid-cols-[minmax(12rem,1.6fr)_minmax(7rem,0.7fr)_minmax(8rem,0.8fr)_minmax(8rem,0.8fr)_minmax(7rem,0.7fr)_1.25rem] md:items-center md:gap-3 ${
                                                    entry.status === 'cancelled'
                                                        ? 'opacity-65'
                                                        : ''
                                                }`}
                                            >
                                                <div className="flex min-w-0 items-start gap-3">
                                                    <div className="bg-muted mt-0.5 rounded-full p-1.5">
                                                        <TypeIcon
                                                            className={`size-4 ${typeIconClass(entry)}`}
                                                        />
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="truncate font-medium">
                                                            {entry.description}
                                                        </p>
                                                        <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                                            <Badge
                                                                variant={
                                                                    entry.status ===
                                                                    'confirmed'
                                                                        ? 'secondary'
                                                                        : 'outline'
                                                                }
                                                            >
                                                                {
                                                                    entry.status_label
                                                                }
                                                            </Badge>
                                                            {entry.refund_status !==
                                                                'none' && (
                                                                <Badge variant="outline">
                                                                    {entry.refund_status_label}
                                                                </Badge>
                                                            )}
                                                            {entry.financial_recurrence_id !==
                                                                null && (
                                                                <Badge variant="outline">
                                                                    <Repeat2 />
                                                                    {entry.recurrence_is_overridden
                                                                        ? 'Ajustada'
                                                                        : 'Recorrência'}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <p className="text-muted-foreground mt-0.5 truncate text-xs">
                                                            {entrySubtitle(
                                                                entry,
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>

                                                <p className="text-muted-foreground hidden text-sm md:block md:text-foreground">
                                                    {formatDate(
                                                        entry.transaction_date,
                                                    )}
                                                </p>
                                                <p className="text-muted-foreground hidden truncate text-sm md:block md:text-foreground">
                                                    {categoryLabel(entry)}
                                                </p>
                                                <p className="text-muted-foreground hidden truncate text-sm md:block md:text-foreground">
                                                    {settlementLabel(entry)}
                                                </p>
                                                <p
                                                    className={`text-right text-sm font-semibold tabular-nums ${amountClass(entry)}`}
                                                >
                                                    {amountPrefix(entry)}
                                                    {currency.format(
                                                        Number(
                                                            entry.type === 'expense'
                                                                ? entry.net_amount
                                                                : entry.amount,
                                                        ),
                                                    )}
                                                    {entry.type === 'expense' &&
                                                        entry.refund_status !== 'none' && (
                                                            <span className="text-muted-foreground block text-[11px] font-normal">
                                                                original{' '}
                                                                {currency.format(
                                                                    Number(entry.amount),
                                                                )}
                                                            </span>
                                                        )}
                                                </p>
                                                <ArrowRight className="text-muted-foreground hidden size-4 shrink-0 transition-transform group-hover:translate-x-0.5 md:block" />
                                            </Link>
                                        );
                                    })}
                                </div>
                            </Card>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

TransactionsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Lançamentos',
            href: index(),
        },
    ],
};
