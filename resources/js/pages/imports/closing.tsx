import { Form, Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarCheck2,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleDashed,
    FileQuestion,
    History,
    ListChecks,
    LockKeyhole,
    Upload,
} from 'lucide-react';
import { ImportsNavigation } from '@/components/imports/imports-navigation';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type {
    MonthlyImportClosingItem,
    MonthlyImportClosingSummary,
    MonthlyImportClosingStatus,
} from '@/types';

type Props = {
    period: string;
    filters: {
        status: string;
    };
    summary: MonthlyImportClosingSummary;
    accounts: MonthlyImportClosingItem[];
    cards: MonthlyImportClosingItem[];
};

const date = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const month = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const filterOptions = [
    ['all', 'Todos'],
    ['pending', 'Pendentes'],
    ['not_imported', 'Não importados'],
    ['to_reconcile', 'A conciliar'],
    ['reconciled', 'Conciliados'],
    ['closed', 'Fechados'],
] as const;

function formatDate(value: string | null) {
    return value ? date.format(new Date(value + 'T00:00:00Z')) : '—';
}

function formatDateTime(value: string | null) {
    return value ? dateTime.format(new Date(value)) : '—';
}

function formatMonth(value: string) {
    const [year, monthValue] = value.split('-').map(Number);
    const label = month.format(new Date(Date.UTC(year, monthValue - 1, 1)));

    return label.charAt(0).toUpperCase() + label.slice(1);
}

function shiftPeriod(value: string, delta: number) {
    const [year, monthValue] = value.split('-').map(Number);
    const shifted = new Date(Date.UTC(year, monthValue - 1 + delta, 1));

    return String(shifted.getUTCFullYear()) + '-' + String(shifted.getUTCMonth() + 1).padStart(2, '0');
}

function statusClasses(status: MonthlyImportClosingStatus) {
    if (status === 'closed' || status === 'reconciled' || status === 'no_movement') {
        return 'border-positive/30 bg-positive-muted text-foreground';
    }

    if (status === 'not_imported') {
        return 'border-destructive/30 bg-destructive/10 text-destructive';
    }

    return 'border-warning/40 bg-warning-muted text-warning-foreground';
}

function StatusIcon({ status }: { status: MonthlyImportClosingStatus }) {
    if (status === 'closed' || status === 'reconciled') {
        return <CheckCircle2 className="text-positive size-5 shrink-0" />;
    }

    if (status === 'not_imported') {
        return <FileQuestion className="text-destructive size-5 shrink-0" />;
    }

    if (status === 'no_movement') {
        return <CircleDashed className="text-positive size-5 shrink-0" />;
    }

    return <AlertTriangle className="text-warning size-5 shrink-0" />;
}

function historyHref(item: MonthlyImportClosingItem, period: string) {
    const query = new URLSearchParams({ kind: item.kind, period });
    query.set(item.source_type === 'account' ? 'account' : 'card', String(item.id));

    return '/importacoes?' + query.toString() + '#historico';
}

function reconciliationHref(item: MonthlyImportClosingItem, period: string) {
    const query = new URLSearchParams({
        kind: item.kind,
        period,
        view: 'pending',
    });
    query.set(item.source_type === 'account' ? 'account' : 'card', String(item.id));

    return '/conciliacao?' + query.toString();
}

function SourceItem({ item, period }: { item: MonthlyImportClosingItem; period: string }) {
    const isAccount = item.source_type === 'account';
    const isIncomplete = isAccount && item.import_count > 0 && !item.coverage_complete;

    return (
        <div className="rounded-lg border bg-card p-4 shadow-sm">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 flex-1">
                    <div className="flex items-start gap-3">
                        <StatusIcon status={item.status} />
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h3 className="font-semibold">{item.name}</h3>
                                <Badge variant="outline" className={statusClasses(item.status)}>
                                    {item.status_label}
                                </Badge>
                                {isIncomplete && (
                                    <Badge
                                        variant="outline"
                                        className="border-warning/40 bg-warning-muted text-warning-foreground"
                                    >
                                        Período incompleto
                                    </Badge>
                                )}
                            </div>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {[item.institution, item.last_four ? 'final ' + item.last_four : null]
                                    .filter(Boolean)
                                    .join(' · ') || 'Origem financeira'}
                            </p>
                        </div>
                    </div>

                    <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        {isAccount ? (
                            <div>
                                <p className="text-muted-foreground text-xs uppercase">Cobertura</p>
                                <p className="mt-1 font-medium tabular-nums">
                                    {item.import_count === 0
                                        ? 'Extrato não importado'
                                        : formatDate(item.coverage_start_on) + ' a ' + formatDate(item.coverage_end_on)}
                                </p>
                            </div>
                        ) : (
                            <div>
                                <p className="text-muted-foreground text-xs uppercase">Fatura</p>
                                <p className="mt-1 font-medium">Referência {formatMonth(item.reference_month)}</p>
                            </div>
                        )}
                        <div>
                            <p className="text-muted-foreground text-xs uppercase">
                                {isAccount ? 'Movimentos' : 'Itens'}
                            </p>
                            <p className="mt-1 font-medium tabular-nums">{item.total_items}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-xs uppercase">Tratamento</p>
                            <p className="mt-1 font-medium tabular-nums">
                                {item.reconciled_items} conciliados
                                {item.ignored_items > 0 ? ' · ' + item.ignored_items + ' ignorados' : ''}
                                {item.pending_items > 0 ? ' · ' + item.pending_items + ' pendentes' : ''}
                            </p>
                        </div>
                        {!isAccount && (
                            <div>
                                <p className="text-muted-foreground text-xs uppercase">Vencimento / valor</p>
                                <p className="mt-1 font-medium tabular-nums">
                                    {formatDate(item.due_date)}
                                    {item.statement_amount !== null
                                        ? ' · ' + currency.format(Number(item.statement_amount))
                                        : ''}
                                </p>
                            </div>
                        )}
                    </div>

                    {item.closure_needs_review && (
                        <p className="text-warning-foreground mt-3 text-xs font-medium">
                            Houve alteração após um fechamento anterior. O período precisa ser confirmado novamente.
                        </p>
                    )}
                    {(item.status === 'closed' || item.status === 'no_movement') && item.closed_at && (
                        <p className="text-muted-foreground mt-3 text-xs">
                            {item.status === 'no_movement' ? 'Sem movimento confirmado' : 'Fechado'} em{' '}
                            {formatDateTime(item.closed_at)}
                            {item.closed_by_name ? ' por ' + item.closed_by_name : ''}.
                        </p>
                    )}

                    {item.imports.length > 0 && (
                        <details className="mt-4 rounded-md border bg-muted/30 px-3 py-2">
                            <summary className="cursor-pointer text-sm font-medium">
                                {item.import_count === 1
                                    ? 'Ver importação relacionada'
                                    : 'Ver ' + item.import_count + ' importações relacionadas'}
                            </summary>
                            <div className="mt-3 space-y-3">
                                {item.imports.map((financialImport) => (
                                    <div key={financialImport.id} className="rounded-md border bg-background p-3 text-sm">
                                        <p className="font-medium">{financialImport.source_filename}</p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            Importado em {formatDateTime(financialImport.imported_at)} ·{' '}
                                            {financialImport.total_records} registros ·{' '}
                                            {financialImport.imported_records} novos ·{' '}
                                            {financialImport.duplicate_records} duplicados
                                        </p>
                                        {financialImport.categorized_automatically !== null && (
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {financialImport.categorized_automatically} categorizados automaticamente ·{' '}
                                                {financialImport.automatically_reconciled ?? 0} conciliados automaticamente ·{' '}
                                                {financialImport.remaining_exceptions ?? 0} exceções restantes
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </details>
                    )}
                </div>

                <div className="flex shrink-0 flex-wrap gap-2 lg:max-w-72 lg:justify-end">
                    {item.status === 'not_imported' && (
                        <>
                            <Button asChild>
                                <Link href="/importacoes">
                                    <Upload />
                                    {isAccount ? 'Importar extrato' : 'Importar fatura'}
                                </Link>
                            </Button>
                            <Form
                                action="/importacoes/fechamento"
                                method="post"
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <>
                                        <input type="hidden" name="source_type" value={item.source_type} />
                                        <input type="hidden" name="source_id" value={item.id} />
                                        <input type="hidden" name="period" value={period} />
                                        <input type="hidden" name="action" value="no_movement" />
                                        <Button variant="outline" disabled={processing}>
                                            <CircleDashed />
                                            Sem movimento
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </>
                    )}
                    {item.status === 'pending_reconciliation' && (
                        <Button asChild>
                            <Link href={reconciliationHref(item, period)}>
                                <ListChecks />
                                Conciliar
                            </Link>
                        </Button>
                    )}
                    {item.can_close && (
                        <Form
                            action="/importacoes/fechamento"
                            method="post"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <>
                                    <input type="hidden" name="source_type" value={item.source_type} />
                                    <input type="hidden" name="source_id" value={item.id} />
                                    <input type="hidden" name="period" value={period} />
                                    <input type="hidden" name="action" value="close" />
                                    <Button disabled={processing}>
                                        <LockKeyhole />
                                        Marcar como fechado
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                    {isIncomplete && (
                        <Button variant="outline" asChild>
                            <Link href="/importacoes">
                                <Upload />
                                Completar período
                            </Link>
                        </Button>
                    )}
                    {item.import_count > 0 && (
                        <Button variant="outline" asChild>
                            <Link href={historyHref(item, period)}>
                                <History />
                                Histórico
                            </Link>
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function Closing({ period, filters, summary, accounts, cards }: Props) {
    const visit = (nextPeriod: string, status = filters.status) => {
        router.get(
            '/importacoes/fechamento',
            {
                period: nextPeriod,
                ...(status !== 'all' ? { status } : {}),
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const setStatus = (status: string) => visit(period, status);

    return (
        <>
            <Head title="Fechamento mensal de importações" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Importações · Checklist mensal
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">Fechamento mensal</h1>
                        <p className="text-muted-foreground mt-1 max-w-3xl text-sm">
                            Confira todas as contas e cartões ativos do workspace. Uma origem continua aparecendo mesmo quando nenhum documento foi importado.
                        </p>
                    </div>
                    <ImportsNavigation active="closing" />
                </div>

                <Card>
                    <CardContent className="flex flex-col gap-4 p-4 md:flex-row md:items-center md:justify-between">
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Mês anterior"
                                onClick={() => visit(shiftPeriod(period, -1))}
                            >
                                <ChevronLeft />
                            </Button>
                            <div className="min-w-48 text-center">
                                <p className="text-muted-foreground text-xs uppercase">Período</p>
                                <p className="font-semibold">{formatMonth(period)}</p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Mês seguinte"
                                onClick={() => visit(shiftPeriod(period, 1))}
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                        <Input
                            type="month"
                            value={period}
                            onChange={(event) => visit(event.target.value)}
                            className="w-full md:w-48"
                            aria-label="Escolher mês e ano"
                        />
                    </CardContent>
                </Card>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-muted-foreground text-xs uppercase">Concluídas</p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {summary.completed} de {summary.total}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-muted-foreground text-xs uppercase">Não importadas</p>
                            <p className="text-destructive mt-1 text-2xl font-semibold tabular-nums">
                                {summary.not_imported}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-muted-foreground text-xs uppercase">A conciliar</p>
                            <p className="text-warning-foreground mt-1 text-2xl font-semibold tabular-nums">
                                {summary.pending_reconciliation}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-muted-foreground text-xs uppercase">Período incompleto</p>
                            <p className="text-warning-foreground mt-1 text-2xl font-semibold tabular-nums">
                                {summary.incomplete}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-muted-foreground text-xs uppercase">Prontas para fechar</p>
                            <p className="text-positive mt-1 text-2xl font-semibold tabular-nums">
                                {summary.ready_to_close}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap gap-2" aria-label="Filtros do fechamento">
                    {filterOptions.map(([value, label]) => (
                        <Button
                            key={value}
                            type="button"
                            size="sm"
                            variant={filters.status === value ? 'secondary' : 'outline'}
                            onClick={() => setStatus(value)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Contas bancárias e financeiras</CardTitle>
                        <CardDescription>
                            A cobertura mensal considera o período efetivamente informado pelos extratos e pode combinar mais de uma importação.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {accounts.length === 0 ? (
                            <p className="text-muted-foreground py-6 text-center text-sm">
                                Nenhuma conta corresponde ao filtro selecionado.
                            </p>
                        ) : (
                            accounts.map((item) => <SourceItem key={'account-' + item.id} item={item} period={period} />)
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Cartões de crédito</CardTitle>
                        <CardDescription>
                            Cartões são conferidos pela referência da fatura, independentemente da data em que o arquivo foi enviado.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {cards.length === 0 ? (
                            <p className="text-muted-foreground py-6 text-center text-sm">
                                Nenhum cartão corresponde ao filtro selecionado.
                            </p>
                        ) : (
                            cards.map((item) => <SourceItem key={'card-' + item.id} item={item} period={period} />)
                        )}
                    </CardContent>
                </Card>

                {summary.pending_total === 0 && summary.total > 0 && (
                    <div className="border-positive/30 bg-positive-muted flex items-center gap-3 rounded-lg border p-4">
                        <CalendarCheck2 className="text-positive size-5" />
                        <p className="text-sm font-medium">Todas as fontes ativas estão fechadas neste período.</p>
                    </div>
                )}
            </div>
        </>
    );
}

Closing.layout = {
    breadcrumbs: [
        { title: 'Importações', href: '/importacoes' },
        { title: 'Fechamento mensal', href: '/importacoes/fechamento' },
    ],
};
