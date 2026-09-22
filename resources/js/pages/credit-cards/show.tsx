import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CreditCard as CreditCardIcon,
    Pencil,
    ReceiptText,
} from 'lucide-react';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { sortListing } from '@/lib/listing';
import { edit as editCard, index, show } from '@/routes/credit-cards';
import { show as showInvoice } from '@/routes/credit-card-invoices';
import { edit as editTransaction } from '@/routes/transactions';
import type {
    CreditCard,
    CreditCardInvoiceOverview,
    CreditCardTransactionOverview,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    card: CreditCard;
    currentInvoice: CreditCardInvoiceOverview | null;
    nextInvoice: CreditCardInvoiceOverview | null;
    transactions: CreditCardTransactionOverview[];
    filters: ListingQueryState;
    hasRecords: boolean;
    statusOptions: ListingFilterOption[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const date = new Intl.DateTimeFormat('pt-BR', {
    timeZone: 'UTC',
});

const month = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

function formatDate(value: string) {
    return date.format(new Date(value + 'T00:00:00Z'));
}

function formatMonth(value: string) {
    return month.format(new Date(value + 'T00:00:00Z'));
}

function invoiceVariant(status: CreditCardInvoiceOverview['status']) {
    if (status === 'overdue') return 'destructive' as const;
    if (status === 'paid') return 'secondary' as const;

    return 'outline' as const;
}

function InvoicePreview({
    title,
    invoice,
}: {
    title: string;
    invoice: CreditCardInvoiceOverview | null;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {invoice === null ? (
                    <div className="text-muted-foreground flex min-h-28 flex-col items-center justify-center gap-2 text-center text-sm">
                        <ReceiptText className="size-5" />
                        Nenhuma fatura em aberto.
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-lg font-semibold capitalize">
                                    {formatMonth(invoice.reference_month)}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    Vence em {formatDate(invoice.due_date)}
                                </p>
                            </div>
                            <Badge variant={invoiceVariant(invoice.status)}>
                                {invoice.status_label}
                            </Badge>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-xs uppercase">
                                Em aberto
                            </p>
                            <p className="text-xl font-semibold tabular-nums">
                                {currency.format(
                                    Number(invoice.outstanding_amount),
                                )}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Fatura:{' '}
                                {currency.format(Number(invoice.total_amount))}
                            </p>
                        </div>
                        <Button variant="outline" className="w-full" asChild>
                            <Link href={showInvoice(invoice.id)}>
                                Ver fatura
                            </Link>
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function CreditCardShow() {
    const {
        card,
        currentInvoice,
        nextInvoice,
        transactions,
        filters,
        hasRecords,
        statusOptions,
    } = usePage<Props>().props;
    const listUrl = show.url(card.id);
    const onSort = (column: string) =>
        sortListing(
            listUrl,
            filters,
            column,
            column === 'description' || column === 'status' ? 'asc' : 'desc',
        );

    return (
        <>
            <Head title={card.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={index()}
                                aria-label="Voltar para cartões"
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {card.name}
                                </h1>
                                <Badge
                                    variant={
                                        card.is_active ? 'secondary' : 'outline'
                                    }
                                >
                                    {card.is_active ? 'Ativo' : 'Inativo'}
                                </Badge>
                            </div>
                            <p className="text-muted-foreground text-sm">
                                {card.institution || 'Sem instituição'} · final{' '}
                                {card.last_four}
                                {card.holder_name
                                    ? ' · ' + card.holder_name
                                    : ''}
                            </p>
                        </div>
                    </div>

                    <Button variant="outline" asChild>
                        <Link href={editCard(card.id)}>
                            <Pencil />
                            Editar cartão
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Limite total
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(card.credit_limit))}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Limite utilizado
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(card.used_limit))}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Compras e parcelas em aberto
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Limite disponível
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(card.available_limit))}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Ciclo
                            </p>
                            <p className="mt-1 text-lg font-semibold">
                                Fecha dia {card.closing_day}
                            </p>
                            <p className="text-muted-foreground text-sm">
                                Vence dia {card.due_day}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <InvoicePreview
                        title="Fatura atual"
                        invoice={currentInvoice}
                    />
                    <InvoicePreview
                        title="Próxima fatura"
                        invoice={nextInvoice}
                    />
                </div>

                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Lançamentos do cartão
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Compras mais recentes e situação das parcelas.
                        </p>
                    </div>
                    <Badge variant="outline">
                        {transactions.length}{' '}
                        {transactions.length === 1
                            ? 'lançamento'
                            : 'lançamentos'}
                    </Badge>
                </div>

                {!hasRecords ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-3 py-10 text-center">
                            <CreditCardIcon className="text-muted-foreground size-6" />
                            <div>
                                <p className="font-medium">
                                    Nenhum lançamento neste cartão
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    As compras feitas no cartão aparecerão aqui.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <ListingToolbar
                            url={listUrl}
                            query={filters}
                            searchPlaceholder="Buscar lançamento…"
                            selects={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    value: filters.status,
                                    options: statusOptions,
                                    allLabel: 'Todos',
                                },
                            ]}
                        />

                        {transactions.length === 0 ? (
                            <ListingEmpty />
                        ) : (
                            <Card className="gap-0 overflow-hidden py-0">
                                <div className="text-muted-foreground hidden grid-cols-[minmax(0,1.6fr)_minmax(7rem,0.6fr)_minmax(7rem,0.6fr)_minmax(7rem,0.6fr)_minmax(6rem,0.5fr)] gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid">
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
                                        column="status"
                                        label="Status"
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
                                    <span className="text-right">Ações</span>
                                </div>
                                <div className="divide-y">
                                    {transactions.map((transaction) => (
                                        <div
                                            key={transaction.id}
                                            className={`grid grid-cols-1 gap-3 px-4 py-3 md:grid-cols-[minmax(0,1.6fr)_minmax(7rem,0.6fr)_minmax(7rem,0.6fr)_minmax(7rem,0.6fr)_minmax(6rem,0.5fr)] md:items-center md:gap-3 ${
                                                transaction.status ===
                                                'cancelled'
                                                    ? 'opacity-65'
                                                    : ''
                                            }`}
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {transaction.description}
                                                </p>
                                                <p className="text-muted-foreground mt-1 truncate text-xs">
                                                    {transaction.category_name
                                                        ? transaction.category_name
                                                        : 'Sem categoria'}
                                                    {transaction.installment_count >
                                                    1
                                                        ? ` · ${transaction.installment_count} parcelas`
                                                        : ''}
                                                    {transaction.next_due_date
                                                        ? ` · vence ${formatDate(transaction.next_due_date)}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <p className="text-muted-foreground hidden text-sm md:block md:text-foreground">
                                                {formatDate(
                                                    transaction.transaction_date,
                                                )}
                                            </p>
                                            <Badge
                                                variant="outline"
                                                className="w-fit"
                                            >
                                                {transaction.status_label}
                                            </Badge>
                                            <p className="text-right text-sm font-semibold tabular-nums">
                                                {currency.format(
                                                    Number(transaction.amount),
                                                )}
                                            </p>
                                            <div className="flex justify-end">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={editTransaction(
                                                            transaction.id,
                                                        )}
                                                    >
                                                        <Pencil />
                                                        Editar
                                                    </Link>
                                                </Button>
                                            </div>
                                        </div>
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

CreditCardShow.layout = {
    breadcrumbs: [
        {
            title: 'Cartões',
            href: index(),
        },
    ],
};
