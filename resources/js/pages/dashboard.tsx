import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowUpRight,
    CalendarClock,
    CalendarDays,
    CircleArrowDown,
    CircleArrowUp,
    Clock3,
    Landmark,
    Plus,
    ReceiptText,
    TrendingDown,
    TrendingUp,
    WalletCards,
} from 'lucide-react';
import { CashFlowChart } from '@/components/dashboard/cash-flow-chart';
import { MetricCard } from '@/components/dashboard/metric-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import {
    createExpense,
    createIncome,
    index as transactionsIndex,
} from '@/routes/transactions';
import type {
    CategoryExpense,
    DashboardEntry,
    DashboardPageProps,
} from '@/types';

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const monthYear = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const shortDate = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: 'short',
    timeZone: 'UTC',
});

const categoryColors = [
    'bg-chart-1',
    'bg-chart-2',
    'bg-chart-3',
    'bg-chart-4',
    'bg-chart-5',
];

function money(value: string) {
    return currency.format(Number(value));
}

function date(value: string) {
    return shortDate.format(new Date(`${value}T00:00:00Z`)).replace('.', '');
}

function entryIcon(type: DashboardEntry['type']) {
    if (type === 'income') {
        return CircleArrowUp;
    }

    if (type === 'expense') {
        return CircleArrowDown;
    }

    return ArrowLeftRight;
}

function EntryRow({
    entry,
    upcoming = false,
}: {
    entry: DashboardEntry;
    upcoming?: boolean;
}) {
    const Icon = entryIcon(entry.type);
    const isIncome = entry.type === 'income';
    const isExpense = entry.type === 'expense';

    return (
        <div className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
            <div
                className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${
                    upcoming
                        ? 'bg-warning-muted text-warning-foreground'
                        : isIncome
                          ? 'bg-positive-muted text-positive'
                          : isExpense
                            ? 'bg-destructive/10 text-destructive'
                            : 'bg-secondary text-primary'
                }`}
            >
                {upcoming ? (
                    <Clock3 className="size-4" aria-hidden="true" />
                ) : (
                    <Icon className="size-4" aria-hidden="true" />
                )}
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="truncate text-sm font-medium">
                            {entry.description}
                        </p>
                        <p className="text-muted-foreground mt-0.5 truncate text-xs">
                            {entry.context ?? entry.category}
                        </p>
                    </div>
                    <div className="shrink-0 text-right">
                        <p
                            className={`text-sm font-semibold tabular-nums ${
                                isIncome
                                    ? 'text-positive'
                                    : isExpense
                                      ? 'text-destructive'
                                      : 'text-foreground'
                            }`}
                        >
                            {isIncome ? '+' : isExpense ? '−' : ''}
                            {money(entry.amount)}
                        </p>
                        <p className="text-muted-foreground mt-0.5 text-xs">
                            {date(entry.date)}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}

function EmptyCardState({ children }: { children: React.ReactNode }) {
    return (
        <div className="bg-muted/25 flex min-h-44 flex-col items-center justify-center gap-3 rounded-lg border border-dashed px-5 py-8 text-center">
            <div className="bg-muted text-muted-foreground flex size-10 items-center justify-center rounded-full">
                <ReceiptText className="size-5" aria-hidden="true" />
            </div>
            <p className="text-muted-foreground max-w-xs text-sm">{children}</p>
        </div>
    );
}

function CategoryList({ items }: { items: CategoryExpense[] }) {
    if (items.length === 0) {
        return (
            <EmptyCardState>
                As despesas confirmadas aparecerão aqui por categoria.
            </EmptyCardState>
        );
    }

    return (
        <div className="space-y-5">
            {items.map((item, index) => (
                <div key={item.name}>
                    <div className="mb-2 flex items-center justify-between gap-3 text-sm">
                        <span className="truncate font-medium">
                            {item.name}
                        </span>
                        <span className="shrink-0 font-semibold tabular-nums">
                            {money(item.amount)}
                        </span>
                    </div>
                    <div className="bg-muted h-2 overflow-hidden rounded-full">
                        <div
                            className={`h-full rounded-full ${categoryColors[index % categoryColors.length]}`}
                            style={{ width: `${item.percentage}%` }}
                            title={`${item.percentage}% das despesas do mês`}
                        />
                    </div>
                    <p className="text-muted-foreground mt-1 text-right text-[11px]">
                        {item.percentage.toLocaleString('pt-BR')}%
                    </p>
                </div>
            ))}
        </div>
    );
}

export default function Dashboard() {
    const {
        currentPeriod,
        metrics,
        cashFlow,
        categoryExpenses,
        upcomingEntries,
        recentEntries,
        workspace,
    } = usePage<DashboardPageProps>().props;
    const periodLabel = monthYear.format(
        new Date(`${currentPeriod}T00:00:00Z`),
    );
    const currentBalance = Number(metrics.current_balance);
    const projectedBalance = Number(metrics.projected_balance);

    return (
        <>
            <Head title="Visão geral" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Visão financeira
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">
                            Visão geral
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Acompanhe as finanças de{' '}
                            <span className="text-foreground font-medium">
                                {workspace.current?.name}
                            </span>
                            .
                        </p>
                    </div>

                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <Badge
                            variant="outline"
                            className="bg-card h-9 justify-center gap-2 px-3 capitalize"
                        >
                            <CalendarDays className="text-primary size-4" />
                            {periodLabel}
                        </Badge>
                        <Button variant="outline" asChild>
                            <Link href={createIncome()}>
                                <TrendingUp />
                                Nova receita
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

                <section
                    aria-label="Resumo financeiro"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <MetricCard
                        title="Saldo atual"
                        value={money(metrics.current_balance)}
                        description={`${metrics.active_accounts} ${metrics.active_accounts === 1 ? 'conta ativa' : 'contas ativas'}`}
                        icon={WalletCards}
                        valueClassName={
                            currentBalance < 0 ? 'text-destructive' : undefined
                        }
                    />
                    <MetricCard
                        title="Receitas"
                        value={money(metrics.income)}
                        description="Confirmadas neste mês"
                        icon={TrendingUp}
                        tone="positive"
                        valueClassName="text-positive"
                    />
                    <MetricCard
                        title="Despesas"
                        value={money(metrics.expenses)}
                        description="Confirmadas neste mês"
                        icon={TrendingDown}
                        tone="destructive"
                        valueClassName="text-destructive"
                    />
                    <MetricCard
                        title="Saldo projetado"
                        value={money(metrics.projected_balance)}
                        description="Inclui lançamentos planejados"
                        icon={Landmark}
                        tone="warning"
                        valueClassName={
                            projectedBalance < 0
                                ? 'text-destructive'
                                : undefined
                        }
                    />
                </section>

                <section className="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(18rem,1fr)]">
                    <Card>
                        <CardHeader className="flex-row items-start justify-between gap-4">
                            <div>
                                <CardTitle>Fluxo de caixa</CardTitle>
                                <CardDescription className="mt-1">
                                    Receitas e despesas confirmadas nos últimos
                                    seis meses.
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <CashFlowChart points={cashFlow} />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Despesas por categoria</CardTitle>
                            <CardDescription>
                                Distribuição das despesas confirmadas no mês.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <CategoryList items={categoryExpenses} />
                        </CardContent>
                    </Card>
                </section>

                <section className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader className="flex-row items-start justify-between gap-4">
                            <div>
                                <CardTitle>Próximos vencimentos</CardTitle>
                                <CardDescription className="mt-1">
                                    Compromissos planejados para os próximos 30
                                    dias.
                                </CardDescription>
                            </div>
                            <div className="bg-warning-muted text-warning-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                                <CalendarClock
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </div>
                        </CardHeader>
                        <CardContent>
                            {upcomingEntries.length === 0 ? (
                                <EmptyCardState>
                                    Nenhum vencimento planejado para os próximos
                                    30 dias.
                                </EmptyCardState>
                            ) : (
                                <div className="divide-y">
                                    {upcomingEntries.map((entry) => (
                                        <EntryRow
                                            key={entry.id}
                                            entry={entry}
                                            upcoming
                                        />
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex-row items-start justify-between gap-4">
                            <div>
                                <CardTitle>Transações recentes</CardTitle>
                                <CardDescription className="mt-1">
                                    Últimos lançamentos e transferências.
                                </CardDescription>
                            </div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={transactionsIndex()}>
                                    Ver todos
                                    <ArrowUpRight />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {recentEntries.length === 0 ? (
                                <EmptyCardState>
                                    Cadastre um lançamento para começar a
                                    acompanhar sua movimentação.
                                </EmptyCardState>
                            ) : (
                                <div className="divide-y">
                                    {recentEntries.map((entry) => (
                                        <EntryRow
                                            key={`${entry.type}-${entry.id}`}
                                            entry={entry}
                                        />
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Visão geral',
            href: dashboard(),
        },
    ],
};
