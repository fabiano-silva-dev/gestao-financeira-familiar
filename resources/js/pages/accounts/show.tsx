import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    Landmark,
    Pencil,
    ReceiptText,
} from 'lucide-react';
import { MonthSelector } from '@/components/dashboard/month-selector';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { edit, index, show } from '@/routes/accounts';
import { show as showInvoice } from '@/routes/credit-card-invoices';
import { edit as editTransaction } from '@/routes/transactions';
import type {
    FinancialAccount,
    FinancialAccountMovementOverview,
    FinancialAccountPanoramaSummary,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    account: FinancialAccount;
    summary: FinancialAccountPanoramaSummary;
    movements: FinancialAccountMovementOverview[];
    currentPeriod: string;
    filters: ListingQueryState;
    hasRecords: boolean;
    typeOptions: ListingFilterOption[];
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

const monthYear = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const rowGridClass =
    'md:grid-cols-[minmax(7rem,0.55fr)_minmax(0,1.7fr)_minmax(9rem,0.8fr)_minmax(8rem,0.7fr)_1.25rem]';

function formatDate(value: string) {
    return date.format(new Date(`${value}T00:00:00Z`));
}

function formatMonth(value: string) {
    return monthYear.format(new Date(`${value}T00:00:00Z`));
}

function movementHref(movement: FinancialAccountMovementOverview) {
    if (movement.transaction_id !== null) {
        return editTransaction(movement.transaction_id);
    }

    if (movement.invoice_id !== null) {
        return showInvoice(movement.invoice_id);
    }

    return null;
}

function movementSubtitle(movement: FinancialAccountMovementOverview) {
    const parts = [movement.type_label];

    if (movement.category_name) {
        parts.push(movement.category_name);
    }

    if (movement.counterparty_account_name) {
        parts.push(movement.counterparty_account_name);
    }

    if (movement.credit_card_name) {
        parts.push(movement.credit_card_name);
    }

    if (movement.family_member_name) {
        parts.push(movement.family_member_name);
    }

    return parts.join(' · ');
}

export default function AccountShow() {
    const {
        account,
        summary,
        movements,
        currentPeriod,
        filters,
        hasRecords,
        typeOptions,
    } = usePage<Props>().props;
    const listUrl = show.url(account.id);
    const periodLabel = formatMonth(currentPeriod);

    return (
        <>
            <Head title={account.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-start gap-3">
                        <div className="bg-muted rounded-full p-3">
                            <Landmark className="size-6" />
                        </div>
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {account.name}
                                </h1>
                                <Badge
                                    variant={
                                        account.is_active
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {account.is_active ? 'Ativa' : 'Inativa'}
                                </Badge>
                            </div>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {[
                                    account.institution || 'Sem instituição',
                                    account.agency
                                        ? `Ag. ${account.agency}`
                                        : null,
                                    account.account_number
                                        ? `Conta ${account.account_number}`
                                        : null,
                                    account.type_label,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <MonthSelector
                            currentPeriod={currentPeriod}
                            url={listUrl}
                            query={filters}
                        />
                        <Button variant="outline" asChild>
                            <Link href={edit(account.id)}>
                                <Pencil />
                                Editar conta
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Saldo inicial
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(
                                    Number(summary.period_opening_balance),
                                )}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                No início de {periodLabel}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-5">
                            <div className="flex items-center gap-2">
                                <ArrowDownLeft className="text-muted-foreground size-4" />
                                <p className="text-muted-foreground text-xs uppercase">
                                    Entradas
                                </p>
                            </div>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(summary.inflows))}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Em {periodLabel}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-5">
                            <div className="flex items-center gap-2">
                                <ArrowUpRight className="text-muted-foreground size-4" />
                                <p className="text-muted-foreground text-xs uppercase">
                                    Saídas
                                </p>
                            </div>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(summary.outflows))}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Em {periodLabel}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Saldo final
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(
                                    Number(summary.period_closing_balance),
                                )}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                No fim de {periodLabel}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="gap-1">
                        <CardTitle className="flex items-center gap-2">
                            <ReceiptText className="size-5" />
                            Extrato da conta
                        </CardTitle>
                        <p className="text-muted-foreground text-sm">
                            {summary.movement_count}{' '}
                            {summary.movement_count === 1
                                ? 'lançamento efetivo'
                                : 'lançamentos efetivos'}{' '}
                            em {periodLabel}, do dia mais antigo para o mais
                            recente.
                        </p>
                    </CardHeader>

                    <CardContent className="space-y-4">
                        {hasRecords && (
                            <ListingToolbar
                                url={listUrl}
                                query={filters}
                                searchPlaceholder="Buscar lançamento…"
                                selects={[
                                    {
                                        key: 'type',
                                        label: 'Tipo',
                                        value: filters.type,
                                        options: typeOptions,
                                        allLabel: 'Todos',
                                    },
                                ]}
                            />
                        )}

                        {movements.length === 0 ? (
                            hasRecords ? (
                                <ListingEmpty description="Nenhum lançamento neste período." />
                            ) : (
                                <div className="flex flex-col items-center gap-3 py-10 text-center">
                                    <div className="bg-muted rounded-full p-3">
                                        <ReceiptText className="text-muted-foreground size-5" />
                                    </div>
                                    <div>
                                        <p className="font-medium">
                                            Nenhum lançamento no período
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            O saldo do mês ainda corresponde ao
                                            saldo inicial informado.
                                        </p>
                                    </div>
                                </div>
                            )
                        ) : (
                            <div className="overflow-hidden rounded-lg border">
                                <div
                                    className={`text-muted-foreground hidden gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid ${rowGridClass}`}
                                >
                                    <span>Data</span>
                                    <span>Lançamento</span>
                                    <span>Tipo</span>
                                    <span className="text-right">Valor</span>
                                    <span className="sr-only">Abrir</span>
                                </div>
                                <div className="divide-y">
                                    {movements.map((movement) => {
                                        const href = movementHref(movement);
                                        const isInflow =
                                            Number(movement.amount) >= 0;
                                        const rowClassName = `hover:bg-muted/40 focus-visible:ring-ring group grid grid-cols-1 gap-2 px-4 py-3 transition-colors focus-visible:ring-2 focus-visible:outline-none md:items-center md:gap-3 ${rowGridClass}`;

                                        const content = (
                                            <>
                                                <p className="text-muted-foreground hidden text-sm md:block md:text-foreground">
                                                    {formatDate(
                                                        movement.occurred_on,
                                                    )}
                                                </p>
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="truncate font-medium">
                                                            {
                                                                movement.description
                                                            }
                                                        </p>
                                                        {movement.is_reconciled && (
                                                            <Badge variant="secondary">
                                                                Conciliado
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <p className="text-muted-foreground mt-1 truncate text-xs">
                                                        <span className="md:hidden">
                                                            {formatDate(
                                                                movement.occurred_on,
                                                            )}
                                                            {' · '}
                                                        </span>
                                                        {movementSubtitle(
                                                            movement,
                                                        )}
                                                    </p>
                                                </div>
                                                <p className="text-muted-foreground hidden truncate text-sm md:block md:text-foreground">
                                                    {movement.type_label}
                                                </p>
                                                <p
                                                    className={`text-right text-lg font-semibold tabular-nums ${
                                                        isInflow
                                                            ? 'text-positive'
                                                            : 'text-destructive'
                                                    }`}
                                                >
                                                    {isInflow ? '+' : ''}
                                                    {currency.format(
                                                        Number(movement.amount),
                                                    )}
                                                </p>
                                                {href ? (
                                                    <ArrowRight className="text-muted-foreground hidden size-4 shrink-0 transition-transform group-hover:translate-x-0.5 md:block" />
                                                ) : (
                                                    <span className="hidden md:block" />
                                                )}
                                            </>
                                        );

                                        if (href === null) {
                                            return (
                                                <div
                                                    key={movement.id}
                                                    className={rowClassName}
                                                >
                                                    {content}
                                                </div>
                                            );
                                        }

                                        return (
                                            <Link
                                                key={movement.id}
                                                href={href}
                                                className={rowClassName}
                                            >
                                                {content}
                                            </Link>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AccountShow.layout = {
    breadcrumbs: [
        {
            title: 'Contas financeiras',
            href: index(),
        },
    ],
};
