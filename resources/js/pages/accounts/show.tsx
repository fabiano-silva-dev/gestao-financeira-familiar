import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Landmark,
    Pencil,
    ReceiptText,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { edit, index } from '@/routes/accounts';
import type {
    FinancialAccount,
    FinancialAccountMovementOverview,
    FinancialAccountPanoramaSummary,
} from '@/types';

type Props = {
    account: FinancialAccount;
    summary: FinancialAccountPanoramaSummary;
    movements: FinancialAccountMovementOverview[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const date = new Intl.DateTimeFormat('pt-BR', {
    timeZone: 'UTC',
});

function formatDate(value: string) {
    return date.format(new Date(value + 'T00:00:00Z'));
}

export default function AccountShow() {
    const { account, summary, movements } = usePage<Props>().props;

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
                                {account.institution || 'Sem instituição'} ·{' '}
                                {account.type_label}
                            </p>
                        </div>
                    </div>

                    <Button variant="outline" asChild>
                        <Link href={edit(account.id)}>
                            <Pencil />
                            Editar conta
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Saldo atual
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(
                                    Number(account.current_balance),
                                )}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Saldo efetivo da conta
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
                                Desde o saldo inicial
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
                                Desde o saldo inicial
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Saldo inicial
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(
                                    Number(account.opening_balance),
                                )}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                {account.opening_balance_date
                                    ? 'Em ' +
                                      formatDate(account.opening_balance_date)
                                    : 'Data não informada'}
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
                                ? 'movimento efetivo'
                                : 'movimentos efetivos'}
                            {account.opening_balance_date
                                ? ' após ' +
                                  formatDate(account.opening_balance_date)
                                : ''}
                            . Exibindo os 100 mais recentes.
                        </p>
                    </CardHeader>

                    <CardContent>
                        {movements.length === 0 ? (
                            <div className="flex flex-col items-center gap-3 py-10 text-center">
                                <div className="bg-muted rounded-full p-3">
                                    <ReceiptText className="text-muted-foreground size-5" />
                                </div>
                                <div>
                                    <p className="font-medium">
                                        Nenhum movimento no período
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        O saldo atual ainda corresponde ao saldo
                                        inicial informado.
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <div className="divide-y">
                                {movements.map((movement) => {
                                    const isInflow =
                                        Number(movement.amount) >= 0;

                                    return (
                                        <div
                                            key={movement.id}
                                            className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-medium">
                                                        {movement.description}
                                                    </p>
                                                    <Badge variant="outline">
                                                        {movement.type_label}
                                                    </Badge>
                                                    {movement.is_reconciled && (
                                                        <Badge variant="secondary">
                                                            Conciliado
                                                        </Badge>
                                                    )}
                                                </div>
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    {formatDate(
                                                        movement.occurred_on,
                                                    )}
                                                    {movement.category_name
                                                        ? ' · ' +
                                                          movement.category_name
                                                        : ''}
                                                    {movement.family_member_name
                                                        ? ' · ' +
                                                          movement.family_member_name
                                                        : ''}
                                                    {movement.counterparty_account_name
                                                        ? ' · ' +
                                                          movement.counterparty_account_name
                                                        : ''}
                                                    {movement.credit_card_name
                                                        ? ' · ' +
                                                          movement.credit_card_name
                                                        : ''}
                                                </p>
                                            </div>

                                            <div className="flex items-center gap-2 sm:justify-end">
                                                {isInflow ? (
                                                    <ArrowDownLeft className="text-muted-foreground size-4" />
                                                ) : (
                                                    <ArrowUpRight className="text-muted-foreground size-4" />
                                                )}
                                                <p className="text-lg font-semibold tabular-nums">
                                                    {isInflow ? '+' : ''}
                                                    {currency.format(
                                                        Number(movement.amount),
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    );
                                })}
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
