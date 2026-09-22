import { Form, Head, Link, router } from '@inertiajs/react';
import {
    Eye,
    FileClock,
    History,
    ListChecks,
    RefreshCw,
    Search,
    Upload,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { SortableColumn } from '@/components/listing/sortable-column';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Option = {
    value: string;
    label: string;
};

type ProcessingSummary = {
    automatically_reconciled?: number;
    categorized_automatically?: number;
    pending_categorization?: number;
    pending_confirmation?: number;
    remaining_exceptions?: number;
};

type HistoryItem = {
    id: number;
    financial_account_id: number | null;
    credit_card_id: number | null;
    kind: 'statement' | 'invoice' | 'document';
    kind_label: string;
    source_filename: string;
    target_name: string;
    institution: string | null;
    status: 'processing' | 'needs_confirmation' | 'completed' | 'failed';
    status_label: string;
    reference_month: string | null;
    statement_start_on: string | null;
    statement_end_on: string | null;
    total_records: number;
    resolved_records: number;
    pending_records: number;
    imported_records: number;
    duplicate_records: number;
    processing_summary: ProcessingSummary | null;
    error_message: string | null;
    imported_at: string | null;
    created_at: string | null;
};

type Filters = {
    search: string;
    kind: string;
    status: string;
    sort: string;
    direction: 'asc' | 'desc';
    account: number | null;
    card: number | null;
    period: string | null;
};

type Props = {
    imports: HistoryItem[];
    filters: Filters;
    kindOptions: Option[];
    statusOptions: Option[];
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

function formatDate(value: string | null) {
    return value ? date.format(new Date(`${value}T00:00:00Z`)) : '—';
}

function periodLabel(item: HistoryItem) {
    if (item.kind === 'invoice' && item.reference_month) {
        const [year, month] = item.reference_month.split('-');

        return `${month}/${year}`;
    }

    if (item.statement_start_on || item.statement_end_on) {
        return `${formatDate(item.statement_start_on)} a ${formatDate(item.statement_end_on)}`;
    }

    return '—';
}

function statusVariant(item: HistoryItem) {
    if (item.status === 'failed') {
        return 'destructive' as const;
    }

    if (item.status === 'completed') {
        return 'secondary' as const;
    }

    return 'outline' as const;
}

export default function ImportHistory({
    imports,
    filters,
    kindOptions,
    statusOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const query = (overrides: Partial<Filters> = {}) => {
        const next = { ...filters, ...overrides };

        return {
            search: next.search || undefined,
            kind: next.kind || undefined,
            status: next.status || undefined,
            sort: next.sort || undefined,
            direction: next.direction || undefined,
            account: next.account || undefined,
            card: next.card || undefined,
            period: next.period || undefined,
        };
    };
    const navigate = (overrides: Partial<Filters>) =>
        router.get('/importacoes/historico', query(overrides), {
            preserveState: true,
            preserveScroll: true,
        });
    const onSort = (column: string) => {
        const direction =
            filters.sort === column && filters.direction === 'asc'
                ? 'desc'
                : 'asc';
        navigate({ sort: column, direction });
    };
    const onSearch = (event: FormEvent) => {
        event.preventDefault();
        navigate({ search });
    };

    return (
        <>
            <Head title="Histórico de arquivos importados" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Importações · rastreabilidade
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Histórico de arquivos
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Consulte arquivo, instituição, conta ou cartão,
                            período, processamento e conciliação.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/importacoes">
                                <Upload />
                                Importar arquivo
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/importacoes/fechamento-mensal">
                                <FileClock />
                                Fechamento mensal
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/conciliacao">
                                <ListChecks />
                                Conciliação
                            </Link>
                        </Button>
                    </div>
                </div>

                {(filters.account || filters.card || filters.period) && (
                    <div className="bg-muted/50 flex flex-wrap items-center gap-2 rounded-lg border px-4 py-3 text-sm">
                        <span className="font-medium">Histórico filtrado</span>
                        {filters.period && (
                            <Badge variant="outline">
                                Período {filters.period}
                            </Badge>
                        )}
                        {filters.account && (
                            <Badge variant="outline">
                                Conta #{filters.account}
                            </Badge>
                        )}
                        {filters.card && (
                            <Badge variant="outline">
                                Cartão #{filters.card}
                            </Badge>
                        )}
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.get('/importacoes/historico')}
                        >
                            Limpar contexto
                        </Button>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Filtros</CardTitle>
                        <CardDescription>
                            Pesquisa e filtros preservam o contexto vindo do
                            fechamento mensal.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 lg:grid-cols-[minmax(260px,1fr)_220px_220px]">
                            <form
                                onSubmit={onSearch}
                                className="flex items-center gap-2"
                            >
                                <Input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Buscar arquivo, conta, cartão ou instituição…"
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="icon"
                                    aria-label="Pesquisar"
                                >
                                    <Search />
                                </Button>
                            </form>

                            <Select
                                value={filters.kind || 'all'}
                                onValueChange={(value) =>
                                    navigate({
                                        kind: value === 'all' ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Tipo" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos os tipos
                                    </SelectItem>
                                    {kindOptions.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(value) =>
                                    navigate({
                                        status: value === 'all' ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Situação" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todas as situações
                                    </SelectItem>
                                    {statusOptions.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <History className="size-5" />
                            Arquivos processados
                        </CardTitle>
                        <CardDescription>
                            {imports.length} registro(s) no filtro atual.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {imports.length === 0 ? (
                            <p className="text-muted-foreground py-10 text-center text-sm">
                                Nenhum arquivo encontrado para os filtros
                                informados.
                            </p>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <div className="text-muted-foreground hidden min-w-[1180px] grid-cols-[minmax(180px,1.3fr)_minmax(150px,1fr)_minmax(120px,.8fr)_100px_150px_120px_150px_150px_110px] gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase lg:grid">
                                    <SortableColumn
                                        column="filename"
                                        label="Arquivo"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="target"
                                        label="Conta / cartão"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="institution"
                                        label="Instituição"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="kind"
                                        label="Tipo"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="period"
                                        label="Período"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="status"
                                        label="Situação"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="imported_at"
                                        label="Importado em"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="summary"
                                        label="Resumo"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <span>Ações</span>
                                </div>

                                <div className="divide-y">
                                    {imports.map((item) => {
                                        const reconciliationQuery =
                                            item.kind === 'invoice'
                                                ? `kind=invoice&card=${item.credit_card_id ?? ''}`
                                                : `kind=statement&account=${item.financial_account_id ?? ''}`;
                                        const importPeriod =
                                            item.reference_month ??
                                            filters.period ??
                                            '';

                                        return (
                                            <div
                                                key={item.id}
                                                className="grid gap-3 px-4 py-4 lg:min-w-[1180px] lg:grid-cols-[minmax(180px,1.3fr)_minmax(150px,1fr)_minmax(120px,.8fr)_100px_150px_120px_150px_150px_110px] lg:items-center"
                                            >
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {item.source_filename}
                                                    </p>
                                                    {item.error_message && (
                                                        <p className="text-destructive mt-1 text-xs">
                                                            {item.error_message}
                                                        </p>
                                                    )}
                                                </div>
                                                <p className="truncate text-sm">
                                                    {item.target_name}
                                                </p>
                                                <p className="text-muted-foreground lg:text-foreground truncate text-sm">
                                                    {item.institution ?? '—'}
                                                </p>
                                                <p className="text-sm">
                                                    {item.kind_label}
                                                </p>
                                                <p className="text-sm">
                                                    {periodLabel(item)}
                                                </p>
                                                <Badge
                                                    variant={statusVariant(
                                                        item,
                                                    )}
                                                >
                                                    {item.status_label}
                                                </Badge>
                                                <p className="text-sm">
                                                    {item.imported_at
                                                        ? dateTime.format(
                                                              new Date(
                                                                  item.imported_at,
                                                              ),
                                                          )
                                                        : '—'}
                                                </p>
                                                <div className="text-sm tabular-nums">
                                                    <p>
                                                        {item.resolved_records}/
                                                        {item.total_records}{' '}
                                                        tratados
                                                    </p>
                                                    <p className="text-muted-foreground text-xs">
                                                        {item.pending_records}{' '}
                                                        pendentes ·{' '}
                                                        {item.duplicate_records}{' '}
                                                        duplicados
                                                    </p>
                                                </div>
                                                <div className="flex flex-wrap gap-1">
                                                    {item.kind !==
                                                        'document' && (
                                                        <Button
                                                            asChild
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Abrir conciliação"
                                                        >
                                                            <Link
                                                                href={`/conciliacao?${reconciliationQuery}${importPeriod ? `&period=${importPeriod}` : ''}&import=${item.id}`}
                                                            >
                                                                <Eye />
                                                            </Link>
                                                        </Button>
                                                    )}
                                                    {item.status ===
                                                        'completed' &&
                                                        item.kind !==
                                                            'document' && (
                                                            <Form
                                                                action={`/conciliacao/importacoes/${item.id}/reprocessar`}
                                                                method="post"
                                                                options={{
                                                                    preserveScroll: true,
                                                                }}
                                                            >
                                                                <Button
                                                                    type="submit"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    aria-label="Reprocessar conciliação"
                                                                >
                                                                    <RefreshCw />
                                                                </Button>
                                                            </Form>
                                                        )}
                                                </div>

                                                {item.processing_summary && (
                                                    <div className="bg-muted/50 rounded-md p-3 text-xs lg:col-span-9">
                                                        <span className="font-medium">
                                                            Processamento:
                                                        </span>{' '}
                                                        {item.processing_summary
                                                            .automatically_reconciled ??
                                                            0}{' '}
                                                        conciliados
                                                        automaticamente ·{' '}
                                                        {item.processing_summary
                                                            .categorized_automatically ??
                                                            0}{' '}
                                                        categorizados ·{' '}
                                                        {item.processing_summary
                                                            .pending_categorization ??
                                                            0}{' '}
                                                        sem categoria ·{' '}
                                                        {item.processing_summary
                                                            .pending_confirmation ??
                                                            0}{' '}
                                                        confirmações ·{' '}
                                                        {item.processing_summary
                                                            .remaining_exceptions ??
                                                            0}{' '}
                                                        exceções
                                                    </div>
                                                )}
                                            </div>
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

ImportHistory.layout = {
    breadcrumbs: [
        {
            title: 'Importações',
            href: '/importacoes',
        },
        {
            title: 'Histórico de arquivos',
            href: '/importacoes/historico',
        },
    ],
};
