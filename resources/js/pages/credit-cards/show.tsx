import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    CreditCard as CreditCardIcon,
    Pencil,
    ReceiptText,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { edit as editCard, index } from '@/routes/credit-cards';
import { show as showInvoice } from '@/routes/credit-card-invoices';
import { edit as editTransaction } from '@/routes/transactions';
import type {
    CreditCard,
    CreditCardInvoiceOverview,
    CreditCardTransactionOverview,
} from '@/types';

type Props = {
    card: CreditCard;
    currentInvoice: CreditCardInvoiceOverview | null;
    nextInvoice: CreditCardInvoiceOverview | null;
    transactions: CreditCardTransactionOverview[];
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
    const { card, currentInvoice, nextInvoice, transactions } =
        usePage<Props>().props;

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

                <div className="flex items-center justify-between gap-4">
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

                {transactions.length === 0 ? (
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
                    <div className="space-y-3">
                        {transactions.map((transaction) => (
                            <Card
                                key={transaction.id}
                                className={
                                    transaction.status === 'cancelled'
                                        ? 'opacity-65'
                                        : undefined
                                }
                            >
                                <CardContent className="p-5">
                                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="truncate font-semibold">
                                                    {transaction.description}
                                                </p>
                                                <Badge variant="outline">
                                                    {transaction.status_label}
                                                </Badge>
                                            </div>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {formatDate(
                                                    transaction.transaction_date,
                                                )}
                                                {transaction.category_name
                                                    ? ' · ' +
                                                      transaction.category_name
                                                    : ''}
                                                {transaction.family_member_name
                                                    ? ' · ' +
                                                      transaction.family_member_name
                                                    : ''}
                                            </p>
                                            <div className="text-muted-foreground mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                                <span>
                                                    {transaction.installment_count >
                                                    1
                                                        ? transaction.installment_count +
                                                          ' parcelas'
                                                        : '1 parcela'}
                                                </span>
                                                <span>
                                                    {
                                                        transaction.open_installment_count
                                                    }{' '}
                                                    em aberto
                                                </span>
                                                {transaction.next_due_date && (
                                                    <span className="flex items-center gap-1">
                                                        <CalendarClock className="size-3.5" />
                                                        Próximo vencimento{' '}
                                                        {formatDate(
                                                            transaction.next_due_date,
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex items-center justify-between gap-4 lg:justify-end">
                                            <p className="text-lg font-semibold tabular-nums">
                                                {currency.format(
                                                    Number(transaction.amount),
                                                )}
                                            </p>
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
                                </CardContent>
                            </Card>
                        ))}
                    </div>
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
