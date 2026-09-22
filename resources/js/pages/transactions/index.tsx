import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowRight,
    CircleArrowDown,
    CircleArrowUp,
    Plus,
    ReceiptText,
    Repeat2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { CategoryPicker } from '@/components/transactions/category-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { sortListing } from '@/lib/listing';
import {
    createExpense,
    createIncome,
    createTransfer,
    edit,
    index,
} from '@/routes/transactions';
import type {
    FinancialEntry,
    FinancialEntryReferenceOption,
    FinancialEntryType,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type CategoryListingOption = ListingFilterOption & {
    name?: string;
    type?: FinancialEntryType;
    is_active?: boolean;
};

type Props = {
    entries: FinancialEntry[];
    filters: ListingQueryState;
    hasRecords: boolean;
    typeOptions: ListingFilterOption[];
    statusOptions: ListingFilterOption[];
    settlementOptions: ListingFilterOption[];
    categoryOptions: CategoryListingOption[];
    accountOptions: ListingFilterOption[];
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

function formatDate(value: string) {
    return date.format(new Date(`${value}T00:00:00Z`));
}

function typeIcon(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return ArrowLeftRight;
    }

    return entry.type === 'expense' ? CircleArrowDown : CircleArrowUp;
}

function typeIconClass(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return 'text-primary';
    }

    return entry.type === 'expense' ? 'text-destructive' : 'text-positive';
}

function amountClass(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return 'text-foreground';
    }

    return entry.type === 'expense' ? 'text-destructive' : 'text-positive';
}

function amountPrefix(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return '';
    }

    return entry.type === 'expense' ? '− ' : '+ ';
}

function accountLabel(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        if (entry.source_account_name && entry.destination_account_name) {
            return `${entry.source_account_name} → ${entry.destination_account_name}`;
        }

        return 'Contas não informadas';
    }

    return entry.credit_card_name ?? entry.financial_account_name ?? 'Sem conta';
}

function firstError(errors: Record<string, string>) {
    return (
        Object.values(errors)[0] ?? 'Não foi possível atualizar a categoria.'
    );
}

function settlementLabel(entry: FinancialEntry) {
    if (entry.type === 'transfer') {
        return entry.status === 'confirmed'
            ? 'Saldo atualizado'
            : 'Sem efeito no saldo';
    }

    if (entry.payment_method === 'credit_card') {
        return entry.installment_count > 1
            ? `${entry.installment_count} parcelas`
            : 'Via fatura';
    }

    if (entry.is_settled) {
        const settledOn = entry.settled_on
            ? ` em ${formatDate(entry.settled_on)}`
            : '';

        return `${entry.type === 'expense' ? 'Pago' : 'Recebido'}${settledOn}`;
    }

    if (entry.due_date) {
        return `Pendente · vence ${formatDate(entry.due_date)}`;
    }

    return 'Pendente';
}

function entrySubtitle(entry: FinancialEntry) {
    const parts = [
        entry.type_label,
        accountLabel(entry),
        formatDate(entry.transaction_date),
        entry.origin_label,
    ];

    if (entry.payment_method_label && entry.type !== 'transfer') {
        parts.push(entry.payment_method_label);
    }

    if (entry.family_member_name) {
        parts.push(entry.family_member_name);
    }

    if (
        entry.type !== 'transfer' &&
        entry.competence_date !== entry.transaction_date
    ) {
        parts.push(`competência ${formatDate(entry.competence_date)}`);
    }

    if (entry.payee_name && entry.payee_name !== entry.description) {
        parts.push(entry.payee_name);
    }

    return parts.join(' · ');
}

export default function TransactionsIndex() {
    const {
        entries,
        filters,
        hasRecords,
        typeOptions,
        statusOptions,
        settlementOptions,
        categoryOptions,
        accountOptions,
        workspace,
    } = usePage<Props>().props;
    const listUrl = index.url();
    const onSort = (column: string) =>
        sortListing(
            listUrl,
            filters,
            column,
            column === 'description' || column === 'category' ? 'asc' : 'desc',
        );

    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const [pendingEntryId, setPendingEntryId] = useState<number | null>(null);
    const [bulkPending, setBulkPending] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    const categoryPickerOptions = useMemo<FinancialEntryReferenceOption[]>(
        () =>
            categoryOptions.flatMap((option) => {
                if (
                    option.value === 'none' ||
                    (option.type !== 'income' && option.type !== 'expense')
                ) {
                    return [];
                }

                return [
                    {
                        id: Number(option.value),
                        name: option.name ?? option.label,
                        label: option.label,
                        type: option.type,
                        is_active: option.is_active ?? true,
                    },
                ];
            }),
        [categoryOptions],
    );
    const selectableEntries = entries.filter(
        (entry) => entry.type !== 'transfer',
    );
    const selectedEntries = entries.filter((entry) =>
        selectedIds.has(entry.id),
    );
    const selectedTypes = Array.from(
        new Set(
            selectedEntries
                .map((entry) => entry.type)
                .filter(
                    (type): type is Exclude<FinancialEntryType, 'transfer'> =>
                        type === 'income' || type === 'expense',
                ),
        ),
    );
    const bulkType = selectedTypes.length === 1 ? selectedTypes[0] : null;
    const hasMixedSelectedTypes = selectedTypes.length > 1;
    const allVisibleSelected =
        selectableEntries.length > 0 &&
        selectableEntries.every((entry) => selectedIds.has(entry.id));
    const someVisibleSelected =
        !allVisibleSelected &&
        selectableEntries.some((entry) => selectedIds.has(entry.id));
    const selectionScopeKey = JSON.stringify({
        filters,
        visibleIds: entries.map((entry) => entry.id),
    });

    useEffect(() => {
        setSelectedIds(new Set());
        setActionError(null);
    }, [selectionScopeKey]);

    const setEntrySelected = (entryId: number, checked: boolean) => {
        setSelectedIds((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(entryId);
            } else {
                next.delete(entryId);
            }

            return next;
        });
    };

    const setAllVisibleSelected = (checked: boolean) => {
        setSelectedIds(
            checked
                ? new Set(selectableEntries.map((entry) => entry.id))
                : new Set(),
        );
    };

    const updateEntryCategory = (
        entry: FinancialEntry,
        categoryId: number | null,
    ) => {
        setActionError(null);
        setPendingEntryId(entry.id);

        router.patch(
            '/lancamentos/' + entry.id + '/categoria',
            { category_id: categoryId },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['entries'],
                onError: (errors) => setActionError(firstError(errors)),
                onFinish: () => setPendingEntryId(null),
            },
        );
    };

    const updateSelectedCategory = (categoryId: number | null) => {
        if (selectedIds.size === 0 || hasMixedSelectedTypes) {
            return;
        }

        setActionError(null);
        setBulkPending(true);

        router.patch(
            '/lancamentos/categoria',
            {
                entry_ids: Array.from(selectedIds),
                category_id: categoryId,
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['entries'],
                onSuccess: () => setSelectedIds(new Set()),
                onError: (errors) => setActionError(firstError(errors)),
                onFinish: () => setBulkPending(false),
            },
        );
    };

    return (
        <>
            <Head title="Lançamentos" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="min-w-0">
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Movimentação
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Lançamentos
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Lista das receitas, despesas e transferências de{' '}
                            <span className="text-foreground font-medium">
                                {workspace.current?.name}
                            </span>
                            . Abra uma linha para ver e ajustar o lançamento.
                        </p>
                    </div>

                    <div className="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <Button variant="outline" asChild>
                            <Link href={createIncome()}>
                                <CircleArrowUp />
                                Nova receita
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={createTransfer()}>
                                <ArrowLeftRight />
                                Nova transferência
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

                {!hasRecords ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <ReceiptText className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhum lançamento cadastrado
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Registre a primeira receita, despesa ou
                                    transferência para iniciar o acompanhamento
                                    financeiro.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={createExpense()}>
                                    Cadastrar primeira despesa
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <ListingToolbar
                            url={listUrl}
                            query={filters}
                            searchPlaceholder="Buscar descrição ou estabelecimento…"
                            selects={[
                                {
                                    key: 'type',
                                    label: 'Tipo',
                                    value: filters.type,
                                    options: typeOptions,
                                    allLabel: 'Todos',
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    value: filters.status,
                                    options: statusOptions,
                                    allLabel: 'Todos',
                                },
                                {
                                    key: 'settlement',
                                    label: 'Liquidação',
                                    value: filters.settlement,
                                    options: settlementOptions,
                                    allLabel: 'Todas',
                                },
                                {
                                    key: 'category',
                                    label: 'Categoria',
                                    value: filters.category,
                                    options: categoryOptions,
                                    allLabel: 'Todas',
                                },
                                {
                                    key: 'account',
                                    label: 'Conta',
                                    value: filters.account,
                                    options: accountOptions,
                                    allLabel: 'Todas',
                                },
                            ]}
                            dates={[
                                {
                                    key: 'from',
                                    label: 'De',
                                    value: filters.from,
                                },
                                {
                                    key: 'to',
                                    label: 'Até',
                                    value: filters.to,
                                },
                            ]}
                        />

                        {entries.length === 0 ? (
                            <ListingEmpty />
                        ) : (
                            <>
                                {selectedIds.size > 0 && (
                                    <div className="bg-card flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">
                                                {selectedIds.size}{' '}
                                                {selectedIds.size === 1
                                                    ? 'lançamento selecionado'
                                                    : 'lançamentos selecionados'}
                                            </p>
                                            {hasMixedSelectedTypes && (
                                                <p className="text-muted-foreground mt-0.5 text-xs">
                                                    Para alterar a categoria em
                                                    lote, selecione lançamentos
                                                    do mesmo tipo.
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                            <div className="min-w-56">
                                                <CategoryPicker
                                                    value={null}
                                                    options={
                                                        categoryPickerOptions
                                                    }
                                                    type={bulkType}
                                                    onChange={
                                                        updateSelectedCategory
                                                    }
                                                    disabled={
                                                        hasMixedSelectedTypes
                                                    }
                                                    busy={bulkPending}
                                                    triggerLabel="Alterar categoria"
                                                    confirmLabel="Aplicar categoria"
                                                />
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                disabled={bulkPending}
                                                onClick={() =>
                                                    setSelectedIds(new Set())
                                                }
                                            >
                                                Limpar seleção
                                            </Button>
                                        </div>
                                    </div>
                                )}

                                {actionError && (
                                    <div
                                        role="alert"
                                        className="border-destructive/30 bg-destructive/5 text-destructive rounded-lg border px-3 py-2 text-sm"
                                    >
                                        {actionError}
                                    </div>
                                )}

                                <Card className="gap-0 py-0">
                                    <div className="text-muted-foreground hidden grid-cols-[2.25rem_minmax(12rem,1.6fr)_minmax(7rem,0.7fr)_minmax(10rem,0.9fr)_minmax(8rem,0.8fr)_minmax(7rem,0.7fr)_1.25rem] gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid">
                                        <div className="flex items-center justify-center">
                                            <Checkbox
                                                checked={
                                                    allVisibleSelected
                                                        ? true
                                                        : someVisibleSelected
                                                          ? 'indeterminate'
                                                          : false
                                                }
                                                disabled={
                                                    selectableEntries.length ===
                                                        0 || bulkPending
                                                }
                                                onCheckedChange={(checked) =>
                                                    setAllVisibleSelected(
                                                        checked === true,
                                                    )
                                                }
                                                aria-label="Selecionar todos os lançamentos visíveis nesta página"
                                            />
                                        </div>
                                        <SortableColumn
                                            column="description"
                                            label="Lançamento"
                                            sort={filters.sort}
                                            direction={filters.direction}
                                            onSort={onSort}
                                        />
                                        <SortableColumn
                                            column="date"
                                            label="Data"
                                            sort={filters.sort}
                                            direction={filters.direction}
                                            onSort={onSort}
                                        />
                                        <SortableColumn
                                            column="category"
                                            label="Categoria"
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
                                            column="amount"
                                            label="Valor"
                                            sort={filters.sort}
                                            direction={filters.direction}
                                            onSort={onSort}
                                            align="right"
                                        />
                                        <span className="sr-only">Abrir</span>
                                    </div>
                                    <div className="divide-y">
                                        {entries.map((entry) => {
                                            const TypeIcon = typeIcon(entry);
                                            const isTransfer =
                                                entry.type === 'transfer';
                                            const entryCategoryType =
                                                entry.type === 'income' ||
                                                entry.type === 'expense'
                                                    ? entry.type
                                                    : null;

                                            return (
                                                <div
                                                    key={entry.id}
                                                    className={[
                                                        'hover:bg-muted/40 focus-within:bg-muted/30 grid grid-cols-[2.25rem_minmax(0,1fr)] gap-2 px-4 py-3 transition-colors md:grid-cols-[2.25rem_minmax(12rem,1.6fr)_minmax(7rem,0.7fr)_minmax(10rem,0.9fr)_minmax(8rem,0.8fr)_minmax(7rem,0.7fr)_1.25rem] md:items-center md:gap-3',
                                                        entry.status ===
                                                        'cancelled'
                                                            ? 'opacity-65'
                                                            : '',
                                                    ].join(' ')}
                                                >
                                                    <div className="flex justify-center pt-1 md:pt-0">
                                                        <Checkbox
                                                            checked={selectedIds.has(
                                                                entry.id,
                                                            )}
                                                            disabled={
                                                                isTransfer ||
                                                                bulkPending
                                                            }
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                setEntrySelected(
                                                                    entry.id,
                                                                    checked ===
                                                                        true,
                                                                )
                                                            }
                                                            aria-label={
                                                                'Selecionar ' +
                                                                entry.description
                                                            }
                                                            title={
                                                                isTransfer
                                                                    ? 'Transferências não utilizam categoria.'
                                                                    : undefined
                                                            }
                                                        />
                                                    </div>

                                                    <div className="flex min-w-0 items-start gap-3">
                                                        <div className="bg-muted mt-0.5 rounded-full p-1.5">
                                                            <TypeIcon
                                                                className={
                                                                    'size-4 ' +
                                                                    typeIconClass(
                                                                        entry,
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <Link
                                                                href={edit(
                                                                    entry.id,
                                                                )}
                                                                className="hover:text-primary focus-visible:ring-ring block truncate rounded-sm font-medium focus-visible:ring-2 focus-visible:outline-none"
                                                            >
                                                                {
                                                                    entry.description
                                                                }
                                                            </Link>
                                                            <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                                                <Badge
                                                                    variant={
                                                                        entry.status ===
                                                                        'confirmed'
                                                                            ? 'secondary'
                                                                            : 'outline'
                                                                    }
                                                                >
                                                                    {
                                                                        entry.status_label
                                                                    }
                                                                </Badge>
                                                                {entry.refund_status !==
                                                                    'none' && (
                                                                    <Badge variant="outline">
                                                                        {
                                                                            entry.refund_status_label
                                                                        }
                                                                    </Badge>
                                                                )}
                                                                {entry.financial_recurrence_id !==
                                                                    null && (
                                                                    <Badge variant="outline">
                                                                        <Repeat2 />
                                                                        {entry.recurrence_is_overridden
                                                                            ? 'Ajustada'
                                                                            : 'Recorrência'}
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <p className="text-muted-foreground mt-0.5 truncate text-xs">
                                                                {entrySubtitle(
                                                                    entry,
                                                                )}
                                                            </p>

                                                            <div className="mt-2 md:hidden">
                                                                {isTransfer ? (
                                                                    <p className="text-muted-foreground min-h-9 px-2 py-2 text-sm">
                                                                        Transferência
                                                                    </p>
                                                                ) : (
                                                                    <CategoryPicker
                                                                        value={
                                                                            entry.category_id
                                                                        }
                                                                        currentLabel={
                                                                            entry.category_name
                                                                        }
                                                                        options={
                                                                            categoryPickerOptions
                                                                        }
                                                                        type={
                                                                            entryCategoryType
                                                                        }
                                                                        onChange={(
                                                                            categoryId,
                                                                        ) =>
                                                                            updateEntryCategory(
                                                                                entry,
                                                                                categoryId,
                                                                            )
                                                                        }
                                                                        busy={
                                                                            pendingEntryId ===
                                                                            entry.id
                                                                        }
                                                                    />
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <p className="text-muted-foreground md:text-foreground hidden text-sm md:block">
                                                        {formatDate(
                                                            entry.transaction_date,
                                                        )}
                                                    </p>

                                                    <div className="hidden min-w-0 md:block">
                                                        {isTransfer ? (
                                                            <p className="text-muted-foreground truncate px-2 text-sm">
                                                                Transferência
                                                            </p>
                                                        ) : (
                                                            <CategoryPicker
                                                                value={
                                                                    entry.category_id
                                                                }
                                                                currentLabel={
                                                                    entry.category_name
                                                                }
                                                                options={
                                                                    categoryPickerOptions
                                                                }
                                                                type={
                                                                    entryCategoryType
                                                                }
                                                                onChange={(
                                                                    categoryId,
                                                                ) =>
                                                                    updateEntryCategory(
                                                                        entry,
                                                                        categoryId,
                                                                    )
                                                                }
                                                                busy={
                                                                    pendingEntryId ===
                                                                    entry.id
                                                                }
                                                            />
                                                        )}
                                                    </div>

                                                    <p className="text-muted-foreground md:text-foreground hidden truncate text-sm md:block">
                                                        {settlementLabel(entry)}
                                                    </p>

                                                    <p
                                                        className={
                                                            'col-start-2 text-right text-sm font-semibold tabular-nums md:col-auto ' +
                                                            amountClass(entry)
                                                        }
                                                    >
                                                        {amountPrefix(entry)}
                                                        {currency.format(
                                                            Number(
                                                                entry.type ===
                                                                    'expense'
                                                                    ? entry.net_amount
                                                                    : entry.amount,
                                                            ),
                                                        )}
                                                        {entry.type ===
                                                            'expense' &&
                                                            entry.refund_status !==
                                                                'none' && (
                                                                <span className="text-muted-foreground block text-[11px] font-normal">
                                                                    original{' '}
                                                                    {currency.format(
                                                                        Number(
                                                                            entry.amount,
                                                                        ),
                                                                    )}
                                                                </span>
                                                            )}
                                                    </p>

                                                    <Link
                                                        href={edit(entry.id)}
                                                        aria-label={
                                                            'Abrir ' +
                                                            entry.description
                                                        }
                                                        className="text-muted-foreground hover:text-foreground focus-visible:ring-ring hidden rounded-sm transition-transform hover:translate-x-0.5 focus-visible:ring-2 focus-visible:outline-none md:block"
                                                    >
                                                        <ArrowRight className="size-4 shrink-0" />
                                                    </Link>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </Card>
                            </>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

TransactionsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Lançamentos',
            href: index(),
        },
    ],
};
