import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowUpRight,
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
import { MonthSelector } from '@/components/dashboard/month-selector';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as accountsIndex } from '@/routes/accounts';
import { show as showInvoice } from '@/routes/credit-card-invoices';
import {
    createExpense,
    createIncome,
    edit as editTransaction,
    index as transactionsIndex,
} from '@/routes/transactions';
import type {
    CategoryExpense,
    CashFlowPoint,
    DashboardEntry,
    DashboardPageProps,
} from '@/types';

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
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

function periodQuery(periodStart: string): { period: string } {
    return { period: periodStart.slice(0, 7) };
}

function transactionsHref(query: Record<string, string>) {
    return transactionsIndex({ query });
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

function entryHref(entry: DashboardEntry) {
    if (entry.source === 'invoice') {
        return showInvoice(entry.id);
    }

    return editTransaction(entry.id);
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
    const isOverdue = Boolean(entry.is_overdue);

    return (
        <Link
            href={entryHref(entry)}
            className="hover:bg-muted/40 focus-visible:ring-ring -mx-6 flex items-center gap-3 px-6 py-3 transition-colors first:pt-3 last:pb-3 focus-visible:ring-2 focus-visible:outline-none"
        >
            <div
                className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${
                    isOverdue
                        ? 'bg-destructive/10 text-destructive'
                        : upcoming
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
                            {entry.status_label} · {date(entry.date)}
                        </p>
                    </div>
                </div>
            </div>
        </Link>
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

function CategoryList({
    items,
    hrefForItem,
    emptyHref,
}: {
    items: CategoryExpense[];
    hrefForItem: (item: CategoryExpense) => ReturnType<typeof transactionsHref>;
    emptyHref: ReturnType<typeof transactionsHref>;
}) {
    if (items.length === 0) {
        return (
            <Link
                href={emptyHref}
                className="focus-visible:ring-ring block rounded-lg focus-visible:ring-2 focus-visible:outline-none"
            >
                <EmptyCardState>
                    As despesas confirmadas aparecerão aqui por categoria.
                </EmptyCardState>
            </Link>
        );
    }

    return (
        <div className="space-y-2">
            {items.map((item, index) => (
                <Link
                    key={item.id ?? 'none'}
                    href={hrefForItem(item)}
                    className="hover:bg-muted/40 focus-visible:ring-ring -mx-2 block rounded-lg px-2 py-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
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
                </Link>
            ))}
        </div>
    );
}

function SectionLink({
    href,
    children,
}: {
    href: ReturnType<typeof transactionsHref>;
    children: React.ReactNode;
}) {
    return (
        <Link
            href={href}
            className="hover:text-primary focus-visible:ring-ring rounded-sm transition-colors focus-visible:ring-2 focus-visible:outline-none"
        >
            {children}
        </Link>
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
    const currentBalance = Number(metrics.current_balance);
    const projectedBalance = Number(metrics.projected_balance);
    const period = periodQuery(currentPeriod);
    const incomeHref = transactionsHref({
        type: 'income',
        status: 'confirmed',
        ...period,
    });
    const expensesHref = transactionsHref({
        type: 'expense',
        status: 'confirmed',
        ...period,
    });
    const pendingHref = transactionsHref({
        settlement: 'pending',
        ...period,
    });
    const cashFlowHref = transactionsHref(period);
    const recentHref = transactionsHref(period);
    const categoryHref = (item: CategoryExpense) =>
        transactionsHref({
            type: 'expense',
            status: 'confirmed',
            category: item.id === null ? 'none' : String(item.id),
            ...period,
        });
    const cashFlowMonthHref = (point: CashFlowPoint) =>
        transactionsHref(periodQuery(point.month));
    const cashFlowIncomeHref = (point: CashFlowPoint) =>
        transactionsHref({
            type: 'income',
            ...periodQuery(point.month),
        });
    const cashFlowExpensesHref = (point: CashFlowPoint) =>
        transactionsHref({
            type: 'expense',
            ...periodQuery(point.month),
        });

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
                        <MonthSelector currentPeriod={currentPeriod} />
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
                        href={accountsIndex()}
                        ariaLabel="Ver contas financeiras"
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
                        href={incomeHref}
                        ariaLabel="Ver receitas confirmadas do período"
                        valueClassName="text-positive"
                    />
                    <MetricCard
                        title="Despesas"
                        value={money(metrics.expenses)}
                        description="Confirmadas neste mês"
                        icon={TrendingDown}
                        tone="destructive"
                        href={expensesHref}
                        ariaLabel="Ver despesas confirmadas do período"
                        valueClassName="text-destructive"
                    />
                    <MetricCard
                        title="Saldo projetado"
                        value={money(metrics.projected_balance)}
                        description="Inclui compromissos pendentes e faturas"
                        icon={Landmark}
                        tone="warning"
                        href={pendingHref}
                        ariaLabel="Ver lançamentos pendentes de liquidação"
                        valueClassName={
                            projectedBalance < 0
                                ? 'text-destructive'
                                : undefined
                        }
                    />
                </section>

                <section className="grid items-start gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(18rem,1fr)]">
                    <Card>
                        <CardHeader className="flex-row items-start justify-between gap-4">
                            <div>
                                <CardTitle>
                                    <SectionLink href={cashFlowHref}>
                                        Fluxo de caixa
                                    </SectionLink>
                                </CardTitle>
                                <CardDescription className="mt-1">
                                    Entradas e saídas efetivas e compromissos
                                    ainda em aberto.
                                </CardDescription>
                            </div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={cashFlowHref}>
                                    Ver período
                                    <ArrowUpRight />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <CashFlowChart
                                points={cashFlow}
                                monthHref={cashFlowMonthHref}
                                incomeHref={cashFlowIncomeHref}
                                expensesHref={cashFlowExpensesHref}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex-row items-start justify-between gap-4">
                            <div>
                                <CardTitle>
                                    <SectionLink href={expensesHref}>
                                        Despesas por categoria
                                    </SectionLink>
                                </CardTitle>
                                <CardDescription className="mt-1">
                                    Distribuição das despesas confirmadas no
                                    mês.
                                </CardDescription>
                            </div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={expensesHref}>
                                    Ver todas
                                    <ArrowUpRight />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <CategoryList
                                items={categoryExpenses}
                                hrefForItem={categoryHref}
                                emptyHref={expensesHref}
                            />
                        </CardContent>
                    </Card>
                </section>

                <section className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader className="flex-row items-start justify-between gap-4">
                            <div>
                                <CardTitle>
                                    <SectionLink href={pendingHref}>
                                        Próximos vencimentos
                                    </SectionLink>
                                </CardTitle>
                                <CardDescription className="mt-1">
                                    Compromissos pendentes, próximos e
                                    vencidos.
                                </CardDescription>
                            </div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={pendingHref}>
                                    Ver todos
                                    <ArrowUpRight />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {upcomingEntries.length === 0 ? (
                                <Link
                                    href={pendingHref}
                                    className="focus-visible:ring-ring block rounded-lg focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <EmptyCardState>
                                        Nenhum vencimento pendente ou atrasado
                                        neste momento.
                                    </EmptyCardState>
                                </Link>
                            ) : (
                                <div className="divide-y">
                                    {upcomingEntries.map((entry) => (
                                        <EntryRow
                                            key={`${entry.source}-${entry.id}`}
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
                                <CardTitle>
                                    <SectionLink href={recentHref}>
                                        Transações recentes
                                    </SectionLink>
                                </CardTitle>
                                <CardDescription className="mt-1">
                                    Últimos pagamentos e recebimentos
                                    efetivados.
                                </CardDescription>
                            </div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={recentHref}>
                                    Ver todos
                                    <ArrowUpRight />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {recentEntries.length === 0 ? (
                                <Link
                                    href={recentHref}
                                    className="focus-visible:ring-ring block rounded-lg focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <EmptyCardState>
                                        Cadastre um lançamento para começar a
                                        acompanhar sua movimentação.
                                    </EmptyCardState>
                                </Link>
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
