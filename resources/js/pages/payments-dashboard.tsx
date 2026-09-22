import { Head, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    CircleArrowDown,
    CircleArrowUp,
    Clock3,
    ReceiptText,
    TrendingDown,
    TrendingUp,
    WalletCards,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { MonthSelector } from '@/components/dashboard/month-selector';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import { payments } from '@/routes';
import type {
    PaymentDashboardItem,
    PaymentDashboardPageProps,
    PaymentDashboardUpcomingGroup,
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

function money(value: string) {
    return currency.format(Number(value));
}

function formatDate(value: string) {
    return shortDate.format(new Date(value + 'T00:00:00Z')).replace('.', '');
}

function SummaryCard({
    title,
    value,
    description,
    icon: Icon,
    tone = 'neutral',
}: {
    title: string;
    value: string;
    description: string;
    icon: typeof WalletCards;
    tone?: 'neutral' | 'positive' | 'destructive' | 'warning';
}) {
    const toneClass = {
        neutral: 'bg-secondary text-primary',
        positive: 'bg-positive-muted text-positive',
        destructive: 'bg-destructive/10 text-destructive',
        warning: 'bg-warning-muted text-warning-foreground',
    }[tone];

    return (
        <Card>
            <CardContent className="pt-5">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-muted-foreground text-xs font-medium">
                            {title}
                        </p>
                        <p className="mt-1 truncate text-xl font-semibold tabular-nums md:text-2xl">
                            {money(value)}
                        </p>
                    </div>
                    <div
                        className={cn(
                            'flex size-10 shrink-0 items-center justify-center rounded-xl',
                            toneClass,
                        )}
                    >
                        <Icon className="size-5" aria-hidden="true" />
                    </div>
                </div>
                <p className="text-muted-foreground mt-3 text-xs">
                    {description}
                </p>
            </CardContent>
        </Card>
    );
}

type SectionMode = 'payable' | 'paid' | 'receivable' | 'received';

function itemDateLabel(mode: SectionMode, value: string) {
    const prefix = {
        payable: 'Vence',
        paid: 'Pago em',
        receivable: 'Previsto para',
        received: 'Recebido em',
    }[mode];

    return prefix + ' ' + formatDate(value);
}

function ItemSummary({
    item,
    mode,
}: {
    item: PaymentDashboardItem;
    mode: SectionMode;
}) {
    return (
        <div className="flex min-w-0 flex-1 items-start justify-between gap-3">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="truncate text-sm font-medium">
                        {item.description}
                    </p>
                    <Badge
                        variant={item.is_overdue ? 'destructive' : 'secondary'}
                    >
                        {item.status_label}
                    </Badge>
                </div>
                <p className="text-muted-foreground mt-1 truncate text-xs">
                    {item.context ?? 'Compromisso financeiro'}
                </p>
            </div>
            <div className="shrink-0 text-right">
                <p className="text-sm font-semibold tabular-nums">
                    {money(item.amount)}
                </p>
                <p className="text-muted-foreground mt-1 text-xs">
                    {itemDateLabel(mode, item.date)}
                </p>
            </div>
        </div>
    );
}

function ItemRow({
    item,
    mode,
}: {
    item: PaymentDashboardItem;
    mode: SectionMode;
}) {
    const expandable = item.details.length > 0 || item.children.length > 0;

    if (!expandable) {
        return (
            <div className="-mx-4 flex items-center gap-3 px-4 py-3 md:-mx-6 md:px-6">
                <ItemSummary item={item} mode={mode} />
            </div>
        );
    }

    return (
        <Collapsible>
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className="hover:bg-muted/40 focus-visible:ring-ring group -mx-4 flex w-[calc(100%+2rem)] items-center gap-3 px-4 py-3 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none md:-mx-6 md:w-[calc(100%+3rem)] md:px-6"
                >
                    <ItemSummary item={item} mode={mode} />
                    <ChevronDown className="text-muted-foreground size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180" />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className="bg-muted/20 -mx-4 border-t px-4 py-4 md:-mx-6 md:px-6">
                    {item.details.length > 0 && (
                        <div className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                            {item.details.map((detail) => (
                                <div key={item.id + '-' + detail.label}>
                                    <p className="text-muted-foreground text-xs">
                                        {detail.label}
                                    </p>
                                    <p className="mt-0.5 text-sm font-medium">
                                        {detail.value}
                                    </p>
                                </div>
                            ))}
                        </div>
                    )}

                    {item.children.length > 0 && (
                        <div className={item.details.length > 0 ? 'mt-4' : ''}>
                            <p className="mb-2 text-xs font-semibold tracking-wide uppercase">
                                Compras da fatura
                            </p>
                            <div className="divide-y rounded-lg border bg-card">
                                {item.children.map((child) => (
                                    <div
                                        key={child.id}
                                        className="flex items-start justify-between gap-4 px-3 py-2.5"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">
                                                {child.description}
                                            </p>
                                            <p className="text-muted-foreground mt-0.5 text-xs">
                                                {[child.meta, child.date ? formatDate(child.date) : null]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        {child.amount !== null && (
                                            <p className="shrink-0 text-sm font-semibold tabular-nums">
                                                {money(child.amount)}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

function EmptyState({ children }: { children: ReactNode }) {
    return (
        <div className="bg-muted/25 flex min-h-32 flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-5 py-7 text-center">
            <div className="bg-muted text-muted-foreground flex size-9 items-center justify-center rounded-full">
                <ReceiptText className="size-4" aria-hidden="true" />
            </div>
            <p className="text-muted-foreground max-w-sm text-sm">{children}</p>
        </div>
    );
}

function PaymentSection({
    title,
    description,
    items,
    mode,
}: {
    title: string;
    description: string;
    items: PaymentDashboardItem[];
    mode: SectionMode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <EmptyState>Nenhum item nesta seção para o mês selecionado.</EmptyState>
                ) : (
                    <div className="divide-y">
                        {items.map((item) => (
                            <ItemRow key={item.id} item={item} mode={mode} />
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function UpcomingCard({
    group,
    payable,
}: {
    group: PaymentDashboardUpcomingGroup;
    payable: PaymentDashboardItem[];
}) {
    const items = payable.filter((item) => group.item_ids.includes(item.id));
    const attention = group.key === 'overdue' || group.key === 'today';

    return (
        <Collapsible>
            <div className="rounded-lg border bg-card">
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        className="focus-visible:ring-ring group flex min-h-20 w-full items-center justify-between gap-3 rounded-lg px-4 py-3 text-left focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <div className="flex items-center gap-2">
                            <span
                                className={cn(
                                    'flex size-8 items-center justify-center rounded-lg',
                                    attention
                                        ? 'bg-destructive/10 text-destructive'
                                        : 'bg-warning-muted text-warning-foreground',
                                )}
                            >
                                <Clock3 className="size-4" aria-hidden="true" />
                            </span>
                            <div>
                                <p className="text-sm font-medium">{group.label}</p>
                                <p className="text-muted-foreground text-xs">
                                    {group.count}{' '}
                                    {group.count === 1 ? 'compromisso' : 'compromissos'}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="text-sm font-semibold tabular-nums">
                                {money(group.amount)}
                            </span>
                            <ChevronDown className="text-muted-foreground size-4 transition-transform group-data-[state=open]:rotate-180" />
                        </div>
                    </button>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div className="border-t px-4 py-3">
                        {items.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Nenhum compromisso neste grupo.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {items.map((item) => (
                                    <div
                                        key={group.key + '-' + item.id}
                                        className="flex items-center justify-between gap-3 text-sm"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {item.description}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                {formatDate(item.date)}
                                            </p>
                                        </div>
                                        <p className="shrink-0 font-semibold tabular-nums">
                                            {money(item.amount)}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </CollapsibleContent>
            </div>
        </Collapsible>
    );
}

export default function PaymentsDashboard() {
    const {
        currentPeriod,
        metrics,
        payable,
        paid,
        receivable,
        received,
        upcoming,
    } = usePage<PaymentDashboardPageProps>().props;
    const projectedBalance = Number(metrics.projected_balance);

    return (
        <>
            <Head title="Dashboard de pagamentos" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Fluxo operacional
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">
                            Dashboard de pagamentos
                        </h1>
                        <p className="text-muted-foreground mt-1 max-w-2xl text-sm">
                            Veja o que já entrou e saiu do caixa e os compromissos previstos para o mês.
                        </p>
                    </div>
                    <MonthSelector currentPeriod={currentPeriod} url={payments.url()} />
                </div>

                <section
                    aria-label="Resumo de pagamentos do mês"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"
                >
                    <SummaryCard
                        title="Pago"
                        value={metrics.paid}
                        description="Saídas efetivadas no mês"
                        icon={CircleArrowDown}
                        tone="destructive"
                    />
                    <SummaryCard
                        title="A pagar"
                        value={metrics.payable}
                        description="Compromissos ainda pendentes"
                        icon={TrendingDown}
                        tone="warning"
                    />
                    <SummaryCard
                        title="Recebido"
                        value={metrics.received}
                        description="Entradas efetivadas no mês"
                        icon={CircleArrowUp}
                        tone="positive"
                    />
                    <SummaryCard
                        title="A receber"
                        value={metrics.receivable}
                        description="Entradas ainda previstas"
                        icon={TrendingUp}
                    />
                    <SummaryCard
                        title="Saldo projetado"
                        value={metrics.projected_balance}
                        description="Entradas previstas menos saídas previstas do mês"
                        icon={WalletCards}
                        tone={projectedBalance < 0 ? 'destructive' : 'positive'}
                    />
                </section>

                <Card>
                    <CardHeader>
                        <CardTitle>Próximos vencimentos</CardTitle>
                        <CardDescription>
                            Vencidos, compromissos de hoje, próximos sete dias e restante do mês.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        {upcoming.map((group) => (
                            <UpcomingCard
                                key={group.key}
                                group={group}
                                payable={payable}
                            />
                        ))}
                    </CardContent>
                </Card>

                <section className="grid items-start gap-6 xl:grid-cols-2">
                    <PaymentSection
                        title="A pagar"
                        description="Faturas, contas, parcelas e demais compromissos que exigem saída de caixa."
                        items={payable}
                        mode="payable"
                    />
                    <PaymentSection
                        title="A receber"
                        description="Receitas e recebimentos previstos para o período."
                        items={receivable}
                        mode="receivable"
                    />
                    <PaymentSection
                        title="Pago"
                        description="Pagamentos efetivamente realizados dentro do mês selecionado."
                        items={paid}
                        mode="paid"
                    />
                    <PaymentSection
                        title="Recebido"
                        description="Entradas efetivamente recebidas dentro do mês selecionado."
                        items={received}
                        mode="received"
                    />
                </section>

                <div className="bg-positive-muted/50 text-muted-foreground flex items-start gap-3 rounded-xl border px-4 py-3 text-sm">
                    <CheckCircle2
                        className="text-positive mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        Compras de cartão aparecem somente dentro da expansão da fatura. O pagamento da fatura movimenta o caixa, mas não cria uma nova despesa.
                    </p>
                </div>
            </div>
        </>
    );
}

PaymentsDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Pagamentos',
            href: payments(),
        },
    ],
};
