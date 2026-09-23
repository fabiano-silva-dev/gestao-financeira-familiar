import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    ArrowUpDown,
    CheckCheck,
    Filter,
    ListFilter,
    Search,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import {
    type FormEvent,
    type ReactNode,
    useEffect,
    useMemo,
    useState,
} from 'react';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { CreateRulePromptDialog } from '@/components/reconciliation/create-rule-prompt-dialog';
import { ReconciliationDialog } from '@/components/reconciliation/reconciliation-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { listingUrl, sortListing, visitListing } from '@/lib/listing';
import { formatReconciliationDate } from '@/lib/reconciliation';
import { cn } from '@/lib/utils';
import { index as classificationRulesIndex } from '@/routes/classification-rules';
import { index } from '@/routes/reconciliation';
import type {
    ClassificationRulePrompt,
    ListingQueryState,
    ReconciliationAccountOption,
    ReconciliationCardOption,
    ReconciliationCategoryOption,
    ReconciliationFilters,
    ReconciliationPendingEntry,
    ReconciliationView,
    ReconciliationViewCounts,
} from '@/types';

type Props = {
    entries: ReconciliationPendingEntry[];
    filters: ReconciliationFilters & ListingQueryState;
    scopeReady: boolean;
    viewCounts: ReconciliationViewCounts;
    accountOptions: ReconciliationAccountOption[];
    cardOptions: ReconciliationCardOption[];
    counterpartAccountOptions: ReconciliationAccountOption[];
    categoryOptions: ReconciliationCategoryOption[];
    pendingEntriesCount: number;
    reconciledEntriesCount: number;
    ignoredEntriesCount: number;
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const monthFormatter = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const statusOptions: Array<[ReconciliationView, string]> = [
    ['all', 'Todos'],
    ['pending', 'Não conciliados'],
    ['reconciled', 'Conciliados'],
    ['ignored', 'Ignorados'],
    ['suggestions', 'Com sugestão'],
    ['duplicates', 'Possíveis duplicidades'],
    ['uncategorized', 'Sem categoria'],
    ['transfers', 'Transferências'],
];

const typeOptions: Array<[ReconciliationFilters['entry_type'], string]> = [
    ['all', 'Todos'],
    ['expense', 'Despesa'],
    ['income', 'Receita'],
    ['card_purchase', 'Compra no cartão'],
    ['transfer', 'Transferência'],
    ['invoice_payment', 'Pagamento de fatura'],
    ['refund', 'Reembolso'],
];

function entryKey(entry: ReconciliationPendingEntry): string {
    return `${entry.kind}:${entry.id}`;
}

function categoryParts(entry: ReconciliationPendingEntry): string[] {
    const parent =
        entry.parent_category_name ??
        entry.related_parent_category_name ??
        entry.matcher_parent_category_name ??
        entry.category_name ??
        entry.related_category_name ??
        entry.matcher_category_name;
    const child =
        entry.subcategory_name ??
        entry.related_subcategory_name ??
        entry.matcher_subcategory_name;

    return [parent, child].filter(
        (value, index, list): value is string =>
            Boolean(value) && list.indexOf(value) === index,
    );
}

function entryCategoryOrLink(entry: ReconciliationPendingEntry): string {
    if (entry.related_is_transfer || entry.is_likely_transfer) {
        return entry.related_counterpart_account_name
            ? `Contrapartida: ${entry.related_counterpart_account_name}`
            : 'Transferência entre contas';
    }

    if (entry.is_invoice_payment || entry.is_likely_invoice_payment) {
        return entry.invoice_label
            ? `${entry.card_name ?? 'Cartão'} · ${entry.invoice_label}`
            : 'Pagamento de cartão/fatura';
    }

    if (entry.is_likely_refund) {
        return entry.related_description
            ? `Reembolso: ${entry.related_description}`
            : 'Reembolso';
    }

    return categoryParts(entry).join(' › ') || 'Sem categoria';
}

function entryTypeLabel(entry: ReconciliationPendingEntry): string {
    if (entry.is_invoice_payment || entry.is_likely_invoice_payment) {
        return 'Pagamento de fatura';
    }

    if (entry.is_likely_refund) {
        return 'Reembolso';
    }

    if (entry.related_is_transfer || entry.is_likely_transfer) {
        return 'Transferência';
    }

    if (entry.kind === 'invoice') {
        return 'Compra no cartão';
    }

    return Number(entry.amount) < 0 ? 'Despesa' : 'Receita';
}

function entryStatus(entry: ReconciliationPendingEntry): {
    label: string;
    variant: 'default' | 'secondary' | 'destructive' | 'outline';
} {
    if (entry.is_ignored) {
        return { label: 'Ignorado', variant: 'outline' };
    }

    if (entry.is_reconciled) {
        return { label: 'Conciliado', variant: 'secondary' };
    }

    if (entry.is_possible_duplicate) {
        return { label: 'Revisar duplicidade', variant: 'destructive' };
    }

    if (entry.has_suggestion || entry.matcher_rule_id !== null) {
        return { label: 'Sugestão', variant: 'outline' };
    }

    return { label: 'Pendente', variant: 'outline' };
}

function shiftMonth(period: string | null, amount: number): string {
    const match = period?.match(/^(\d{4})-(\d{2})$/);

    if (!match) {
        return period ?? '';
    }

    const date = new Date(
        Date.UTC(Number(match[1]), Number(match[2]) - 1 + amount, 1),
    );

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

function monthLabel(period: string | null): string {
    if (!period) {
        return 'Período';
    }

    return monthFormatter.format(new Date(`${period}-01T00:00:00Z`));
}

type HeaderProps = {
    label: string;
    sortKey: string;
    filters: ReconciliationFilters & ListingQueryState;
    listUrl: string;
    active?: boolean;
    children?: ReactNode;
    align?: 'left' | 'right';
};

function ColumnHeader({
    label,
    sortKey,
    filters,
    listUrl,
    active = false,
    children,
    align = 'left',
}: HeaderProps) {
    const sorted = filters.sort === sortKey;
    const SortIcon = !sorted
        ? ArrowUpDown
        : filters.direction === 'asc'
          ? ArrowUp
          : ArrowDown;

    return (
        <div
            className={cn(
                'flex items-center gap-1',
                align === 'right' && 'justify-end',
            )}
        >
            <button
                type="button"
                className="hover:text-foreground flex min-w-0 items-center gap-1 font-medium"
                onClick={() => sortListing(listUrl, filters, sortKey)}
            >
                <span className="truncate">{label}</span>
                <SortIcon className="size-3.5 shrink-0" />
            </button>
            {children && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className={cn(
                                'size-7 shrink-0',
                                active && 'text-primary bg-primary/10',
                            )}
                            aria-label={`Filtrar ${label}`}
                        >
                            <Filter className="size-3.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    {children}
                </DropdownMenu>
            )}
        </div>
    );
}

function DescriptionFilter({
    filters,
    listUrl,
}: {
    filters: ReconciliationFilters & ListingQueryState;
    listUrl: string;
}) {
    const [value, setValue] = useState(filters.q ?? '');

    useEffect(() => setValue(filters.q ?? ''), [filters.q]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        visitListing(listUrl, filters, { q: value.trim() || null });
    };

    return (
        <DropdownMenuContent align="start" className="w-72 p-3">
            <form className="grid gap-2" onSubmit={submit}>
                <DropdownMenuLabel className="px-0">
                    Filtrar descrição
                </DropdownMenuLabel>
                <Input
                    autoFocus
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    onKeyDown={(event) => event.stopPropagation()}
                    placeholder="Contém..."
                />
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setValue('');
                            visitListing(listUrl, filters, { q: null });
                        }}
                    >
                        Limpar
                    </Button>
                    <Button type="submit" size="sm">
                        <Search />
                        Filtrar
                    </Button>
                </div>
            </form>
        </DropdownMenuContent>
    );
}

export default function ReconciliationIndex({
    entries,
    filters,
    scopeReady,
    viewCounts,
    accountOptions,
    cardOptions,
    counterpartAccountOptions,
    categoryOptions,
    pendingEntriesCount,
    reconciledEntriesCount,
    ignoredEntriesCount,
}: Props) {
    const [detailsEntry, setDetailsEntry] =
        useState<ReconciliationPendingEntry | null>(null);
    const [rulePrompt, setRulePrompt] =
        useState<ClassificationRulePrompt | null>(null);
    const [selected, setSelected] = useState<string[]>([]);
    const [bulkCategoryId, setBulkCategoryId] = useState('');
    const listUrl = index.url();

    useEffect(() => {
        const available = new Set(entries.map(entryKey));
        setSelected((current) => current.filter((key) => available.has(key)));
        setDetailsEntry((current) => {
            if (!current) {
                return null;
            }

            return (
                entries.find(
                    (entry) =>
                        entry.kind === current.kind && entry.id === current.id,
                ) ?? null
            );
        });
    }, [entries]);

    const selectedEntries = useMemo(
        () => entries.filter((entry) => selected.includes(entryKey(entry))),
        [entries, selected],
    );
    const selectedTypes = new Set(
        selectedEntries.map((entry) =>
            entry.kind === 'invoice' || Number(entry.amount) < 0
                ? 'expense'
                : 'income',
        ),
    );
    const bulkCategoryType =
        selectedTypes.size === 1
            ? (Array.from(selectedTypes)[0] as 'expense' | 'income')
            : null;
    const bulkCategories = categoryOptions.filter(
        (category) => category.type === bulkCategoryType,
    );
    const ignorable = selectedEntries.filter(
        (entry) => !entry.is_reconciled && !entry.is_ignored,
    );
    const ruleEligible = selectedEntries.filter(
        (entry) =>
            entry.kind === 'statement' &&
            !entry.is_reconciled &&
            !entry.is_ignored &&
            entry.matcher_rule_id !== null &&
            entry.matcher_category_id !== null,
    );
    const allSelected =
        entries.length > 0 && selected.length === entries.length;
    const someSelected =
        selected.length > 0 && selected.length < entries.length;

    const selectionPayload = (items = selectedEntries) =>
        items.map((entry) => ({ kind: entry.kind, id: entry.id }));

    const bulkAdjust = (
        action: 'category' | 'ignore',
        items = selectedEntries,
    ) => {
        if (items.length === 0) {
            return;
        }

        router.post(
            listingUrl('/conciliacao/lote/ajustar', filters),
            {
                action,
                entries: selectionPayload(items),
                category_id:
                    action === 'category' && bulkCategoryId !== ''
                        ? Number(bulkCategoryId)
                        : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected([]);
                    setBulkCategoryId('');
                },
            },
        );
    };

    const confirmRules = () => {
        if (ruleEligible.length === 0) {
            return;
        }

        router.post(
            listingUrl('/conciliacao/lote/regras', filters),
            { entries: ruleEligible.map((entry) => entry.id) },
            {
                preserveScroll: true,
                onSuccess: () => setSelected([]),
            },
        );
    };

    const categoryLabel = (category: ReconciliationCategoryOption) => {
        const parent = categoryOptions.find(
            (option) => option.id === category.parent_id,
        );

        return parent ? `${parent.name} › ${category.name}` : category.name;
    };

    const sourceValue =
        filters.account !== null
            ? `account:${filters.account}`
            : filters.card !== null
              ? `card:${filters.card}`
              : 'all';

    return (
        <>
            <Head title="Conciliação" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Conferência mensal
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Conciliação
                        </h1>
                        <p className="text-muted-foreground mt-1 max-w-3xl text-sm">
                            Confira o mês inteiro em uma única listagem. Os
                            detalhes e ações ficam disponíveis ao abrir cada linha.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Mês anterior"
                            disabled={!filters.period}
                            onClick={() =>
                                visitListing(listUrl, filters, {
                                    period: shiftMonth(filters.period, -1),
                                    import: null,
                                    from: null,
                                    to: null,
                                })
                            }
                        >
                            <ArrowLeft />
                        </Button>
                        <label className="grid gap-1">
                            <span className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                                Mês da conferência
                            </span>
                            <Input
                                type="month"
                                className="w-44"
                                value={filters.period ?? ''}
                                min="1990-01"
                                max="2100-12"
                                onChange={(event) =>
                                    visitListing(listUrl, filters, {
                                        period: event.target.value || null,
                                        import: null,
                                        from: null,
                                        to: null,
                                    })
                                }
                            />
                        </label>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Próximo mês"
                            disabled={!filters.period}
                            onClick={() =>
                                visitListing(listUrl, filters, {
                                    period: shiftMonth(filters.period, 1),
                                    import: null,
                                    from: null,
                                    to: null,
                                })
                            }
                        >
                            <ArrowRight />
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={classificationRulesIndex()}>
                                <ListFilter />
                                Regras
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="text-muted-foreground flex flex-wrap items-center gap-x-5 gap-y-1 text-sm">
                    <span className="font-medium text-foreground capitalize">
                        {monthLabel(filters.period)}
                    </span>
                    <span>
                        Todos{' '}
                        <strong className="text-foreground tabular-nums">
                            {viewCounts.all}
                        </strong>
                    </span>
                    <span>
                        Pendentes{' '}
                        <strong className="text-foreground tabular-nums">
                            {pendingEntriesCount}
                        </strong>
                    </span>
                    <span>
                        Conciliados{' '}
                        <strong className="text-foreground tabular-nums">
                            {reconciledEntriesCount}
                        </strong>
                    </span>
                    {ignoredEntriesCount > 0 && (
                        <span>
                            Ignorados{' '}
                            <strong className="text-foreground tabular-nums">
                                {ignoredEntriesCount}
                            </strong>
                        </span>
                    )}
                </div>

                {selected.length > 0 && (
                    <div className="bg-muted/50 flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2">
                        <span className="mr-2 text-sm font-medium">
                            {selected.length} selecionado(s)
                        </span>

                        <Select
                            value={bulkCategoryId || 'none'}
                            disabled={bulkCategoryType === null}
                            onValueChange={(value) =>
                                setBulkCategoryId(value === 'none' ? '' : value)
                            }
                        >
                            <SelectTrigger size="sm" className="w-64">
                                <SelectValue
                                    placeholder={
                                        bulkCategoryType === null
                                            ? 'Selecione somente entradas ou saídas'
                                            : 'Alterar categoria em lote'
                                    }
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Selecione a categoria
                                </SelectItem>
                                {bulkCategories.map((category) => (
                                    <SelectItem
                                        key={category.id}
                                        value={String(category.id)}
                                    >
                                        {categoryLabel(category)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            disabled={
                                bulkCategoryType === null ||
                                bulkCategoryId === ''
                            }
                            onClick={() => bulkAdjust('category')}
                        >
                            <SlidersHorizontal />
                            Aplicar categoria
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={ruleEligible.length === 0}
                            onClick={confirmRules}
                        >
                            <CheckCheck />
                            Conciliar regras ({ruleEligible.length})
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={ignorable.length === 0}
                            onClick={() => bulkAdjust('ignore', ignorable)}
                        >
                            Ignorar ({ignorable.length})
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setSelected([]);
                                setBulkCategoryId('');
                            }}
                        >
                            <X />
                            Limpar seleção
                        </Button>
                    </div>
                )}

                {!scopeReady ? (
                    <ListingEmpty
                        title="Selecione um mês"
                        description="Escolha o mês que deseja conferir."
                    />
                ) : entries.length === 0 ? (
                    <ListingEmpty
                        title="Nenhum movimento encontrado"
                        description="Ajuste os filtros das colunas ou escolha outro mês."
                    />
                ) : (
                    <Card className="gap-0 overflow-hidden py-0">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[980px] border-collapse text-sm">
                                <thead className="bg-muted/60 text-muted-foreground">
                                    <tr className="border-b">
                                        <th className="w-10 px-3 py-2 text-left">
                                            <Checkbox
                                                aria-label="Selecionar todos os movimentos filtrados"
                                                checked={
                                                    someSelected
                                                        ? 'indeterminate'
                                                        : allSelected
                                                }
                                                onCheckedChange={() =>
                                                    setSelected(
                                                        allSelected
                                                            ? []
                                                            : entries.map(entryKey),
                                                    )
                                                }
                                            />
                                        </th>
                                        <th className="w-28 px-2 py-2 text-left">
                                            <ColumnHeader
                                                label="Data"
                                                sortKey="date"
                                                filters={filters}
                                                listUrl={listUrl}
                                            />
                                        </th>
                                        <th className="w-56 px-2 py-2 text-left">
                                            <ColumnHeader
                                                label="Conta / Cartão"
                                                sortKey="source"
                                                filters={filters}
                                                listUrl={listUrl}
                                                active={sourceValue !== 'all'}
                                            >
                                                <DropdownMenuContent
                                                    align="start"
                                                    className="max-h-80 w-72 overflow-y-auto"
                                                >
                                                    <DropdownMenuLabel>
                                                        Conta ou cartão
                                                    </DropdownMenuLabel>
                                                    <DropdownMenuRadioGroup
                                                        value={sourceValue}
                                                        onValueChange={(value) => {
                                                            if (value === 'all') {
                                                                visitListing(
                                                                    listUrl,
                                                                    filters,
                                                                    {
                                                                        account: null,
                                                                        card: null,
                                                                        kind: 'all',
                                                                    },
                                                                );
                                                            } else if (
                                                                value.startsWith(
                                                                    'account:',
                                                                )
                                                            ) {
                                                                visitListing(
                                                                    listUrl,
                                                                    filters,
                                                                    {
                                                                        account:
                                                                            value.slice(
                                                                                8,
                                                                            ),
                                                                        card: null,
                                                                        kind: 'statement',
                                                                    },
                                                                );
                                                            } else {
                                                                visitListing(
                                                                    listUrl,
                                                                    filters,
                                                                    {
                                                                        card: value.slice(
                                                                            5,
                                                                        ),
                                                                        account: null,
                                                                        kind: 'invoice',
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        <DropdownMenuRadioItem value="all">
                                                            Todos
                                                        </DropdownMenuRadioItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuLabel>
                                                            Contas
                                                        </DropdownMenuLabel>
                                                        {accountOptions.map(
                                                            (account) => (
                                                                <DropdownMenuRadioItem
                                                                    key={`account:${account.id}`}
                                                                    value={`account:${account.id}`}
                                                                >
                                                                    {account.name}
                                                                </DropdownMenuRadioItem>
                                                            ),
                                                        )}
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuLabel>
                                                            Cartões
                                                        </DropdownMenuLabel>
                                                        {cardOptions.map((card) => (
                                                            <DropdownMenuRadioItem
                                                                key={`card:${card.id}`}
                                                                value={`card:${card.id}`}
                                                            >
                                                                {card.name} · final{' '}
                                                                {card.last_four}
                                                            </DropdownMenuRadioItem>
                                                        ))}
                                                    </DropdownMenuRadioGroup>
                                                </DropdownMenuContent>
                                            </ColumnHeader>
                                        </th>
                                        <th className="px-2 py-2 text-left">
                                            <ColumnHeader
                                                label="Descrição"
                                                sortKey="description"
                                                filters={filters}
                                                listUrl={listUrl}
                                                active={Boolean(filters.q)}
                                            >
                                                <DescriptionFilter
                                                    filters={filters}
                                                    listUrl={listUrl}
                                                />
                                            </ColumnHeader>
                                        </th>
                                        <th className="w-32 px-2 py-2 text-right">
                                            <ColumnHeader
                                                label="Valor"
                                                sortKey="amount"
                                                filters={filters}
                                                listUrl={listUrl}
                                                active={filters.flow !== 'all'}
                                                align="right"
                                            >
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuLabel>
                                                        Fluxo
                                                    </DropdownMenuLabel>
                                                    <DropdownMenuRadioGroup
                                                        value={filters.flow}
                                                        onValueChange={(value) =>
                                                            visitListing(
                                                                listUrl,
                                                                filters,
                                                                {
                                                                    flow:
                                                                        value ===
                                                                        'all'
                                                                            ? null
                                                                            : value,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <DropdownMenuRadioItem value="all">
                                                            Todos
                                                        </DropdownMenuRadioItem>
                                                        <DropdownMenuRadioItem value="out">
                                                            Saídas
                                                        </DropdownMenuRadioItem>
                                                        <DropdownMenuRadioItem value="in">
                                                            Entradas
                                                        </DropdownMenuRadioItem>
                                                    </DropdownMenuRadioGroup>
                                                </DropdownMenuContent>
                                            </ColumnHeader>
                                        </th>
                                        <th className="w-44 px-2 py-2 text-left">
                                            <ColumnHeader
                                                label="Tipo"
                                                sortKey="type"
                                                filters={filters}
                                                listUrl={listUrl}
                                                active={
                                                    filters.entry_type !== 'all'
                                                }
                                            >
                                                <DropdownMenuContent align="start">
                                                    <DropdownMenuLabel>
                                                        Tipo
                                                    </DropdownMenuLabel>
                                                    <DropdownMenuRadioGroup
                                                        value={filters.entry_type}
                                                        onValueChange={(value) =>
                                                            visitListing(
                                                                listUrl,
                                                                filters,
                                                                {
                                                                    entry_type:
                                                                        value ===
                                                                        'all'
                                                                            ? null
                                                                            : value,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {typeOptions.map(
                                                            ([value, label]) => (
                                                                <DropdownMenuRadioItem
                                                                    key={value}
                                                                    value={value}
                                                                >
                                                                    {label}
                                                                </DropdownMenuRadioItem>
                                                            ),
                                                        )}
                                                    </DropdownMenuRadioGroup>
                                                </DropdownMenuContent>
                                            </ColumnHeader>
                                        </th>
                                        <th className="w-64 px-2 py-2 text-left">
                                            <ColumnHeader
                                                label="Categoria / Vínculo"
                                                sortKey="category"
                                                filters={filters}
                                                listUrl={listUrl}
                                                active={filters.category !== null}
                                            >
                                                <DropdownMenuContent
                                                    align="start"
                                                    className="max-h-80 w-72 overflow-y-auto"
                                                >
                                                    <DropdownMenuLabel>
                                                        Categoria
                                                    </DropdownMenuLabel>
                                                    <DropdownMenuRadioGroup
                                                        value={
                                                            filters.category ??
                                                            'all'
                                                        }
                                                        onValueChange={(value) =>
                                                            visitListing(
                                                                listUrl,
                                                                filters,
                                                                {
                                                                    category:
                                                                        value ===
                                                                        'all'
                                                                            ? null
                                                                            : value,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <DropdownMenuRadioItem value="all">
                                                            Todas
                                                        </DropdownMenuRadioItem>
                                                        <DropdownMenuRadioItem value="none">
                                                            Sem categoria
                                                        </DropdownMenuRadioItem>
                                                        <DropdownMenuSeparator />
                                                        {categoryOptions.map(
                                                            (category) => (
                                                                <DropdownMenuRadioItem
                                                                    key={category.id}
                                                                    value={String(
                                                                        category.id,
                                                                    )}
                                                                >
                                                                    {categoryLabel(
                                                                        category,
                                                                    )}
                                                                </DropdownMenuRadioItem>
                                                            ),
                                                        )}
                                                    </DropdownMenuRadioGroup>
                                                </DropdownMenuContent>
                                            </ColumnHeader>
                                        </th>
                                        <th className="w-44 px-2 py-2 text-left">
                                            <ColumnHeader
                                                label="Situação"
                                                sortKey="status"
                                                filters={filters}
                                                listUrl={listUrl}
                                                active={filters.view !== 'all'}
                                            >
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuLabel>
                                                        Situação
                                                    </DropdownMenuLabel>
                                                    <DropdownMenuRadioGroup
                                                        value={filters.view}
                                                        onValueChange={(value) =>
                                                            visitListing(
                                                                listUrl,
                                                                filters,
                                                                {
                                                                    view:
                                                                        value ===
                                                                        'all'
                                                                            ? null
                                                                            : value,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {statusOptions.map(
                                                            ([value, label]) => (
                                                                <DropdownMenuRadioItem
                                                                    key={value}
                                                                    value={value}
                                                                >
                                                                    {label}
                                                                </DropdownMenuRadioItem>
                                                            ),
                                                        )}
                                                    </DropdownMenuRadioGroup>
                                                </DropdownMenuContent>
                                            </ColumnHeader>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.map((entry) => {
                                        const key = entryKey(entry);
                                        const status = entryStatus(entry);

                                        return (
                                            <tr
                                                key={key}
                                                tabIndex={0}
                                                className={cn(
                                                    'hover:bg-muted/40 cursor-pointer border-b last:border-b-0 focus-visible:bg-muted/50 focus-visible:outline-none',
                                                    entry.is_reconciled &&
                                                        'bg-muted/20',
                                                )}
                                                onClick={() =>
                                                    setDetailsEntry(entry)
                                                }
                                                onKeyDown={(event) => {
                                                    if (
                                                        event.key === 'Enter' ||
                                                        event.key === ' '
                                                    ) {
                                                        event.preventDefault();
                                                        setDetailsEntry(entry);
                                                    }
                                                }}
                                            >
                                                <td
                                                    className="px-3 py-2"
                                                    onClick={(event) =>
                                                        event.stopPropagation()
                                                    }
                                                    onKeyDown={(event) =>
                                                        event.stopPropagation()
                                                    }
                                                >
                                                    <Checkbox
                                                        aria-label={`Selecionar ${entry.description}`}
                                                        checked={selected.includes(
                                                            key,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setSelected(
                                                                (current) =>
                                                                    checked
                                                                        ? Array.from(
                                                                              new Set([
                                                                                  ...current,
                                                                                  key,
                                                                              ]),
                                                                          )
                                                                        : current.filter(
                                                                              (
                                                                                  value,
                                                                              ) =>
                                                                                  value !==
                                                                                  key,
                                                                          ),
                                                            )
                                                        }
                                                    />
                                                </td>
                                                <td className="whitespace-nowrap px-2 py-2 tabular-nums">
                                                    {formatReconciliationDate(
                                                        entry.occurred_on,
                                                    )}
                                                </td>
                                                <td className="max-w-56 px-2 py-2">
                                                    <span className="block truncate font-medium">
                                                        {entry.source_name}
                                                    </span>
                                                    <span className="text-muted-foreground block truncate text-xs">
                                                        {entry.source_format_label}
                                                    </span>
                                                </td>
                                                <td className="max-w-96 px-2 py-2">
                                                    <span className="block truncate font-medium">
                                                        {entry.description}
                                                    </span>
                                                    {entry.installment_label && (
                                                        <span className="text-muted-foreground text-xs">
                                                            {entry.installment_label}
                                                        </span>
                                                    )}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'whitespace-nowrap px-2 py-2 text-right font-semibold tabular-nums',
                                                        Number(entry.amount) < 0
                                                            ? 'text-destructive'
                                                            : 'text-positive',
                                                    )}
                                                >
                                                    {currency.format(
                                                        Number(entry.amount),
                                                    )}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {entryTypeLabel(entry)}
                                                </td>
                                                <td className="max-w-64 px-2 py-2">
                                                    <span className="block truncate">
                                                        {entryCategoryOrLink(entry)}
                                                    </span>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <Badge
                                                        variant={status.variant}
                                                        className="whitespace-nowrap"
                                                    >
                                                        {status.label}
                                                    </Badge>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                )}
            </div>

            <ReconciliationDialog
                entry={detailsEntry}
                query={filters}
                categoryOptions={categoryOptions}
                cardOptions={cardOptions}
                counterpartAccountOptions={counterpartAccountOptions}
                onClose={() => setDetailsEntry(null)}
                onAskCreateRule={setRulePrompt}
            />
            <CreateRulePromptDialog
                prompt={rulePrompt}
                onClose={() => setRulePrompt(null)}
            />
        </>
    );
}

ReconciliationIndex.layout = {
    breadcrumbs: [
        {
            title: 'Conciliação',
            href: index(),
        },
    ],
};
