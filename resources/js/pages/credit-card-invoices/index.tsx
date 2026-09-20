import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarClock, CreditCard, ReceiptText } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index, show } from '@/routes/credit-card-invoices';
import type { CreditCardInvoice } from '@/types';

type Props = {
    invoices: CreditCardInvoice[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const month = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
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

export default function CreditCardInvoicesIndex() {
    const { invoices, workspace } = usePage<Props>().props;

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

                {invoices.length === 0 ? (
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
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {invoices.map((invoice) => (
                            <Card key={invoice.id}>
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <CardTitle className="truncate">
                                                {invoice.credit_card_name}
                                            </CardTitle>
                                            <p className="text-muted-foreground mt-1 text-sm capitalize">
                                                {month.format(
                                                    new Date(
                                                        `${invoice.reference_month}T00:00:00Z`,
                                                    ),
                                                )}{' '}
                                                · final{' '}
                                                {invoice.credit_card_last_four}
                                            </p>
                                        </div>
                                        <Badge
                                            variant={statusVariant(
                                                invoice.status,
                                            )}
                                        >
                                            {invoice.status_label}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Valor da fatura
                                        </p>
                                        <p className="text-2xl font-semibold tabular-nums">
                                            {currency.format(
                                                Number(
                                                    invoice.statement_amount ??
                                                        invoice.calculated_amount,
                                                ),
                                            )}
                                        </p>
                                        {invoice.statement_amount && (
                                            <p className="text-muted-foreground text-xs">
                                                Calculado:{' '}
                                                {currency.format(
                                                    Number(
                                                        invoice.calculated_amount,
                                                    ),
                                                )}
                                            </p>
                                        )}
                                    </div>

                                    <div className="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Vencimento
                                            </p>
                                            <p className="font-medium">
                                                {formatDate(invoice.due_date)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Em aberto
                                            </p>
                                            <p className="font-medium tabular-nums">
                                                {currency.format(
                                                    Number(
                                                        invoice.outstanding_amount,
                                                    ),
                                                )}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="text-muted-foreground flex items-center gap-2 text-xs">
                                        <CalendarClock className="size-4" />
                                        Fecha em {formatDate(invoice.closing_date)}
                                    </div>

                                    <Button className="w-full" variant="outline" asChild>
                                        <Link href={show(invoice.id)}>
                                            <CreditCard />
                                            Ver fatura
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
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
