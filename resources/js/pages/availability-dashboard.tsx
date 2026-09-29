import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    ChevronDown,
    ChevronRight,
    CreditCard,
    Landmark,
    WalletCards,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type AccountAvailability = {
    id: number;
    name: string;
    institution: string | null;
    type_label: string;
    current_balance: string;
    overdraft_limit: string;
    overdraft_used: string;
    overdraft_available: string;
};

type CardAvailability = {
    id: number;
    name: string;
    institution: string | null;
    last_four: string | null;
    credit_limit: string;
    used_limit: string;
    available_limit: string;
    closing_day: number;
    due_day: number;
    next_closing_date: string;
    days_until_closing: number;
};

type Receivable = {
    id: number;
    description: string;
    amount: string;
    expected_on: string;
    account_name: string | null;
};

type Props = {
    asOf: string;
    nextMonth: string;
    summary: {
        account_balance: string;
        overdraft_limit: string;
        overdraft_used: string;
        overdraft_available: string;
        card_limit: string;
        card_used: string;
        card_available: string;
        next_month_receivable: string;
    };
    accounts: AccountAvailability[];
    cards: CardAvailability[];
    receivables: Receivable[];
    nextClosingCard: CardAvailability | null;
};

type Detail = 'accounts' | 'overdraft' | 'cards' | null;

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const dateFormatter = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: 'short',
});

const monthFormatter = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
});

function dateValue(value: string) {
    return new Date(value + 'T12:00:00');
}

function formatCurrency(value: string) {
    return currency.format(Number(value));
}

function closingLabel(days: number) {
    if (days === 0) return 'Fecha hoje';
    if (days === 1) return 'Fecha amanhã';

    return 'Fecha em ' + days + ' dias';
}

export default function AvailabilityDashboard({
    asOf,
    nextMonth,
    summary,
    accounts,
    cards,
    receivables,
    nextClosingCard,
}: Props) {
    const [detail, setDetail] = useState<Detail>(null);
    const overdraftAccounts = accounts.filter(
        (account) => Number(account.overdraft_limit) > 0,
    );
    const nextMonthPeriod = nextMonth.slice(0, 7);

    const toggleDetail = (next: Exclude<Detail, null>) => {
        setDetail((current) => (current === next ? null : next));
    };

    return (
        <>
            <Head title="Disponibilidade financeira" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Disponibilidade financeira
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Posição de curto prazo em{' '}
                        {dateFormatter.format(dateValue(asOf))}. Dinheiro
                        próprio e crédito aparecem separados.
                    </p>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <button
                            type="button"
                            className="w-full text-left"
                            onClick={() => toggleDetail('accounts')}
                            aria-expanded={detail === 'accounts'}
                        >
                            <CardContent className="p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Saldo em contas
                                        </p>
                                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                                            {formatCurrency(
                                                summary.account_balance,
                                            )}
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {accounts.length}{' '}
                                            {accounts.length === 1
                                                ? 'conta ativa'
                                                : 'contas ativas'}
                                        </p>
                                    </div>
                                    <Landmark className="text-primary size-5" />
                                </div>
                                <div className="text-primary mt-4 flex items-center gap-1 text-xs font-medium">
                                    Ver por conta
                                    {detail === 'accounts' ? (
                                        <ChevronDown className="size-4" />
                                    ) : (
                                        <ChevronRight className="size-4" />
                                    )}
                                </div>
                            </CardContent>
                        </button>
                    </Card>

                    <Card>
                        <button
                            type="button"
                            className="w-full text-left"
                            onClick={() => toggleDetail('overdraft')}
                            aria-expanded={detail === 'overdraft'}
                        >
                            <CardContent className="p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Cheque especial disponível
                                        </p>
                                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                                            {formatCurrency(
                                                summary.overdraft_available,
                                            )}
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {formatCurrency(
                                                summary.overdraft_used,
                                            )}{' '}
                                            em uso de{' '}
                                            {formatCurrency(
                                                summary.overdraft_limit,
                                            )}
                                        </p>
                                    </div>
                                    <WalletCards className="text-primary size-5" />
                                </div>
                                <div className="text-primary mt-4 flex items-center gap-1 text-xs font-medium">
                                    Ver limites por conta
                                    {detail === 'overdraft' ? (
                                        <ChevronDown className="size-4" />
                                    ) : (
                                        <ChevronRight className="size-4" />
                                    )}
                                </div>
                            </CardContent>
                        </button>
                    </Card>

                    <Card>
                        <button
                            type="button"
                            className="w-full text-left"
                            onClick={() => toggleDetail('cards')}
                            aria-expanded={detail === 'cards'}
                        >
                            <CardContent className="p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Limite disponível nos cartões
                                        </p>
                                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                                            {formatCurrency(
                                                summary.card_available,
                                            )}
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {formatCurrency(summary.card_used)}{' '}
                                            em uso de{' '}
                                            {formatCurrency(
                                                summary.card_limit,
                                            )}
                                        </p>
                                    </div>
                                    <CreditCard className="text-primary size-5" />
                                </div>
                                <div className="text-primary mt-4 flex items-center gap-1 text-xs font-medium">
                                    Ver por cartão
                                    {detail === 'cards' ? (
                                        <ChevronDown className="size-4" />
                                    ) : (
                                        <ChevronRight className="size-4" />
                                    )}
                                </div>
                            </CardContent>
                        </button>
                    </Card>

                    <Card>
                        <Link
                            href={
                                '/pagamentos?period=' +
                                nextMonthPeriod +
                                '#a-receber'
                            }
                            className="block"
                        >
                            <CardContent className="p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Recebimentos previstos
                                        </p>
                                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                                            {formatCurrency(
                                                summary.next_month_receivable,
                                            )}
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-xs capitalize">
                                            {monthFormatter.format(
                                                dateValue(nextMonth),
                                            )}{' '}
                                            · {receivables.length}{' '}
                                            {receivables.length === 1
                                                ? 'entrada'
                                                : 'entradas'}
                                        </p>
                                    </div>
                                    <CalendarClock className="text-primary size-5" />
                                </div>
                                <div className="text-primary mt-4 flex items-center gap-1 text-xs font-medium">
                                    Ver recebimentos do mês
                                    <ArrowRight className="size-4" />
                                </div>
                            </CardContent>
                        </Link>
                    </Card>
                </div>

                {detail !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {detail === 'accounts' && 'Saldo por conta'}
                                {detail === 'overdraft' &&
                                    'Cheque especial por conta'}
                                {detail === 'cards' && 'Limite por cartão'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="divide-y p-0">
                            {detail === 'accounts' &&
                                accounts.map((account) => (
                                    <Link
                                        key={account.id}
                                        href={'/contas/' + account.id}
                                        className="hover:bg-muted/50 flex items-center justify-between gap-4 px-6 py-4"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {account.name}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                {[
                                                    account.institution,
                                                    account.type_label,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3 text-right">
                                            <p className="font-semibold tabular-nums">
                                                {formatCurrency(
                                                    account.current_balance,
                                                )}
                                            </p>
                                            <ArrowRight className="text-muted-foreground size-4" />
                                        </div>
                                    </Link>
                                ))}

                            {detail === 'overdraft' &&
                                (overdraftAccounts.length === 0 ? (
                                    <p className="text-muted-foreground px-6 py-6 text-sm">
                                        Nenhuma conta possui limite de cheque
                                        especial cadastrado.
                                    </p>
                                ) : (
                                    overdraftAccounts.map((account) => (
                                        <Link
                                            key={account.id}
                                            href={'/contas/' + account.id}
                                            className="hover:bg-muted/50 flex items-center justify-between gap-4 px-6 py-4"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {account.name}
                                                </p>
                                                <p className="text-muted-foreground text-xs tabular-nums">
                                                    {formatCurrency(
                                                        account.overdraft_used,
                                                    )}{' '}
                                                    utilizado de{' '}
                                                    {formatCurrency(
                                                        account.overdraft_limit,
                                                    )}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-3 text-right">
                                                <div>
                                                    <p className="font-semibold tabular-nums">
                                                        {formatCurrency(
                                                            account.overdraft_available,
                                                        )}
                                                    </p>
                                                    <p className="text-muted-foreground text-xs">
                                                        disponível
                                                    </p>
                                                </div>
                                                <ArrowRight className="text-muted-foreground size-4" />
                                            </div>
                                        </Link>
                                    ))
                                ))}

                            {detail === 'cards' &&
                                cards.map((card) => (
                                    <Link
                                        key={card.id}
                                        href={'/cartoes/' + card.id}
                                        className="hover:bg-muted/50 flex items-center justify-between gap-4 px-6 py-4"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {card.name}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                {[
                                                    card.institution,
                                                    card.last_four
                                                        ? 'final ' +
                                                          card.last_four
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3 text-right">
                                            <div>
                                                <p className="font-semibold tabular-nums">
                                                    {formatCurrency(
                                                        card.available_limit,
                                                    )}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    disponível
                                                </p>
                                            </div>
                                            <ArrowRight className="text-muted-foreground size-4" />
                                        </div>
                                    </Link>
                                ))}
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(18rem,0.7fr)]">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-4">
                            <div>
                                <CardTitle>
                                    Próximas viradas dos cartões
                                </CardTitle>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    Ordenado pela próxima data de fechamento.
                                </p>
                            </div>
                            <CreditCard className="text-muted-foreground size-5" />
                        </CardHeader>
                        <CardContent className="divide-y p-0">
                            {cards.length === 0 ? (
                                <p className="text-muted-foreground px-6 py-6 text-sm">
                                    Nenhum cartão ativo cadastrado.
                                </p>
                            ) : (
                                cards.map((card, index) => (
                                    <Link
                                        key={card.id}
                                        href={'/cartoes/' + card.id}
                                        className="hover:bg-muted/50 flex items-center justify-between gap-4 px-6 py-4"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="truncate font-medium">
                                                    {card.name}
                                                </p>
                                                {index === 0 && (
                                                    <Badge variant="secondary">
                                                        Próxima virada
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {closingLabel(
                                                    card.days_until_closing,
                                                )}{' '}
                                                · vencimento dia {card.due_day}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="font-medium tabular-nums">
                                                {dateFormatter.format(
                                                    dateValue(
                                                        card.next_closing_date,
                                                    ),
                                                )}
                                            </p>
                                            <p className="text-muted-foreground text-xs tabular-nums">
                                                {formatCurrency(
                                                    card.available_limit,
                                                )}{' '}
                                                disponível
                                            </p>
                                        </div>
                                    </Link>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Próxima virada</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {nextClosingCard === null ? (
                                <p className="text-muted-foreground text-sm">
                                    Cadastre um cartão para acompanhar as
                                    próximas viradas.
                                </p>
                            ) : (
                                <div className="space-y-4">
                                    <div>
                                        <p className="text-lg font-semibold">
                                            {nextClosingCard.name}
                                        </p>
                                        <p className="text-muted-foreground text-sm">
                                            {closingLabel(
                                                nextClosingCard.days_until_closing,
                                            )}{' '}
                                            em{' '}
                                            {dateFormatter.format(
                                                dateValue(
                                                    nextClosingCard.next_closing_date,
                                                ),
                                            )}
                                            .
                                        </p>
                                    </div>
                                    <div className="bg-muted rounded-lg p-4">
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Limite disponível
                                        </p>
                                        <p className="mt-1 text-xl font-semibold tabular-nums">
                                            {formatCurrency(
                                                nextClosingCard.available_limit,
                                            )}
                                        </p>
                                    </div>
                                    <Link
                                        href={
                                            '/cartoes/' + nextClosingCard.id
                                        }
                                        className="text-primary inline-flex items-center gap-1 text-sm font-medium"
                                    >
                                        Abrir cartão
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

AvailabilityDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Disponibilidade financeira',
            href: '/disponibilidade',
        },
    ],
};
