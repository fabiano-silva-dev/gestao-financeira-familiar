import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, ReceiptText } from 'lucide-react';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { sortListing } from '@/lib/listing';
import { index, show } from '@/routes/credit-card-invoices';
import type {
    CreditCardInvoice,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    invoices: CreditCardInvoice[];
    filters: ListingQueryState;
    hasRecords: boolean;
    statusOptions: ListingFilterOption[];
    cardOptions: ListingFilterOption[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const month = new Intl.DateTimeFormat('pt-BR', {
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

const date = new Intl.DateTimeFormat('pt-BR', {
    timeZone: 'UTC',
});

function formatDate(value: string) {
    return date.format(new Date(`${value}T00:00:00Z`));
}

function statusVariant(status: CreditCardInvoice['status']) {
    if (status === 'paid') return 'secondary' as const;
    if (status === 'overdue') return 'destructive' as const;
    return 'outline' as const;
}

const rowGridClass =
    'md:grid-cols-[minmax(0,1.4fr)_minmax(7rem,0.6fr)_minmax(8rem,0.7fr)_minmax(7rem,0.6fr)_minmax(8rem,0.7fr)_1.25rem]';

export default function CreditCardInvoicesIndex() {
    const {
        invoices,
        filters,
        hasRecords,
        statusOptions,
        cardOptions,
        workspace,
    } = usePage<Props>().props;
    const listUrl = index.url();
    const onSort = (column: string) =>
        sortListing(
            listUrl,
            filters,
            column,
            column === 'card' || column === 'status' ? 'asc' : 'desc',
        );

    return (
        <>
            <Head title="Faturas" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Faturas de cartão
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Ciclos, vencimentos e pagamentos de{' '}
                        <span className="font-medium">
                            {workspace.current?.name}
                        </span>
                        .
                    </p>
                </div>

                {!hasRecords ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <ReceiptText className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhuma fatura gerada
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Ao registrar uma despesa no cartão, as
                                    parcelas serão vinculadas automaticamente às
                                    faturas correspondentes.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <ListingToolbar
                            url={listUrl}
                            query={filters}
                            searchPlaceholder="Buscar cartão ou final…"
                            selects={[
                                {
                                    key: 'card',
                                    label: 'Cartão',
                                    value: filters.card,
                                    options: cardOptions,
                                    allLabel: 'Todos',
                                },
                                {
                                    key: 'status',
                                    label: 'Situação',
                                    value: filters.status,
                                    options: statusOptions,
                                    allLabel: 'Todas',
                                },
                            ]}
                        />

                        {invoices.length === 0 ? (
                            <ListingEmpty />
                        ) : (
                            <Card className="gap-0 overflow-hidden py-0">
                                <div
                                    className={`text-muted-foreground hidden gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid ${rowGridClass}`}
                                >
                                    <SortableColumn
                                        column="card"
                                        label="Cartão"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="month"
                                        label="Mês"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="due_date"
                                        label="Vencimento"
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
                                    {invoices.map((invoice) => (
                                        <Link
                                            key={invoice.id}
                                            href={show(invoice.id)}
                                            className={`hover:bg-muted/40 focus-visible:ring-ring group grid grid-cols-1 gap-2 px-4 py-3 transition-colors focus-visible:ring-2 focus-visible:outline-none md:items-center md:gap-3 ${rowGridClass}`}
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {invoice.credit_card_name}
                                                </p>
                                                <p className="text-muted-foreground mt-0.5 truncate text-xs">
                                                    final{' '}
                                                    {
                                                        invoice.credit_card_last_four
                                                    }
                                                </p>
                                            </div>
                                            <p className="text-muted-foreground hidden text-sm capitalize md:block md:text-foreground">
                                                {month.format(
                                                    new Date(
                                                        `${invoice.reference_month}T00:00:00Z`,
                                                    ),
                                                )}
                                            </p>
                                            <p className="text-muted-foreground hidden text-sm md:block md:text-foreground">
                                                {formatDate(invoice.due_date)}
                                            </p>
                                            <Badge
                                                variant={statusVariant(
                                                    invoice.status,
                                                )}
                                                className="w-fit"
                                            >
                                                {invoice.status_label}
                                            </Badge>
                                            <p className="text-right text-sm font-semibold tabular-nums">
                                                {currency.format(
                                                    Number(
                                                        invoice.statement_amount ??
                                                            invoice.calculated_amount,
                                                    ),
                                                )}
                                            </p>
                                            <ArrowRight className="text-muted-foreground hidden size-4 shrink-0 transition-transform group-hover:translate-x-0.5 md:block" />
                                        </Link>
                                    ))}
                                </div>
                            </Card>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

CreditCardInvoicesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Faturas',
            href: index(),
        },
    ],
};
