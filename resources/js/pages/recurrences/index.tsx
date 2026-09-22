import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CircleArrowDown,
    CircleArrowUp,
    Plus,
    Repeat2,
} from 'lucide-react';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { sortListing } from '@/lib/listing';
import { create, edit, index } from '@/routes/recurrences';
import type {
    FinancialRecurrence,
    ListingFilterOption,
    ListingQueryState,
    RecurrenceProjectionPoint,
} from '@/types';

type Props = {
    recurrences: FinancialRecurrence[];
    projection: RecurrenceProjectionPoint[];
    filters: ListingQueryState;
    hasRecords: boolean;
    typeOptions: ListingFilterOption[];
    statusOptions: ListingFilterOption[];
    frequencyOptions: ListingFilterOption[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const monthYear = new Intl.DateTimeFormat('pt-BR', {
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
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

export default function RecurrencesIndex({
    recurrences,
    projection,
    filters,
    hasRecords,
    typeOptions,
    statusOptions,
    frequencyOptions,
}: Props) {
    const listUrl = index.url();
    const onSort = (column: string) =>
        sortListing(
            listUrl,
            filters,
            column,
            column === 'amount' || column === 'next' ? 'desc' : 'asc',
        );
    return (
        <>
            <Head title="Recorrências" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Automação
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Recorrências
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Lista das regras. Abra uma linha para ver e ajustar
                            a recorrência.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Nova recorrência
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">
                            Próximos 6 meses
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
                            {projection.map((month) => {
                                const net = Number(month.net);

                                return (
                                    <div
                                        key={month.month}
                                        className="rounded-lg border px-3 py-2"
                                    >
                                        <p className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                                            {monthYear.format(
                                                new Date(
                                                    `${month.month}T00:00:00Z`,
                                                ),
                                            )}
                                        </p>
                                        <p
                                            className={`mt-1 text-sm font-semibold tabular-nums ${
                                                net < 0
                                                    ? 'text-destructive'
                                                    : ''
                                            }`}
                                        >
                                            {currency.format(net)}
                                        </p>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {!hasRecords ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <Repeat2 className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhuma recorrência cadastrada
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Comece por uma conta mensal, mensalidade ou
                                    receita que se repete.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>
                                    Cadastrar primeira recorrência
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <ListingToolbar
                            url={listUrl}
                            query={filters}
                            searchPlaceholder="Buscar recorrência…"
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
                                    label: 'Situação',
                                    value: filters.status,
                                    options: statusOptions,
                                    allLabel: 'Todas',
                                },
                                {
                                    key: 'frequency',
                                    label: 'Frequência',
                                    value: filters.frequency,
                                    options: frequencyOptions,
                                    allLabel: 'Todas',
                                },
                            ]}
                        />

                        {recurrences.length === 0 ? (
                            <ListingEmpty />
                        ) : (
                    <Card className="gap-0 overflow-hidden py-0">
                        <div className="text-muted-foreground hidden grid-cols-[minmax(0,1.6fr)_minmax(7rem,0.7fr)_minmax(8rem,0.8fr)_minmax(8rem,0.8fr)_minmax(7rem,0.7fr)_1.25rem] gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid">
                            <SortableColumn
                                column="description"
                                label="Recorrência"
                                sort={filters.sort}
                                direction={filters.direction}
                                onSort={onSort}
                            />
                            <SortableColumn
                                column="frequency"
                                label="Frequência"
                                sort={filters.sort}
                                direction={filters.direction}
                                onSort={onSort}
                            />
                            <SortableColumn
                                column="next"
                                label="Próxima"
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
                            {recurrences.map((recurrence) => {
                                const isExpense = recurrence.type === 'expense';
                                const TypeIcon = isExpense
                                    ? CircleArrowDown
                                    : CircleArrowUp;

                                return (
                                    <Link
                                        key={recurrence.id}
                                        href={edit(recurrence.id)}
                                        className={`hover:bg-muted/40 focus-visible:ring-ring group grid grid-cols-1 gap-2 px-4 py-3 transition-colors focus-visible:ring-2 focus-visible:outline-none md:grid-cols-[minmax(0,1.6fr)_minmax(7rem,0.7fr)_minmax(8rem,0.8fr)_minmax(8rem,0.8fr)_minmax(7rem,0.7fr)_1.25rem] md:items-center md:gap-3 ${
                                            recurrence.is_active
                                                ? ''
                                                : 'opacity-65'
                                        }`}
                                    >
                                        <div className="flex min-w-0 items-start gap-3">
                                            <div className="bg-muted mt-0.5 rounded-full p-1.5">
                                                <TypeIcon
                                                    className={`size-4 ${
                                                        isExpense
                                                            ? 'text-destructive'
                                                            : 'text-positive'
                                                    }`}
                                                />
                                            </div>
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="truncate font-medium">
                                                        {recurrence.description}
                                                    </p>
                                                    <Badge
                                                        variant={
                                                            recurrence.is_active
                                                                ? 'secondary'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {recurrence.is_active
                                                            ? 'Ativa'
                                                            : 'Pausada'}
                                                    </Badge>
                                                </div>
                                                <p className="text-muted-foreground mt-0.5 truncate text-xs">
                                                    {recurrence.type_label}
                                                    {' · '}
                                                    {recurrence.credit_card_name ??
                                                        recurrence.financial_account_name ??
                                                        'Sem conta'}
                                                    {recurrence.next_occurrence
                                                        ? ` · próxima ${formatDate(recurrence.next_occurrence)}`
                                                        : ''}
                                                </p>
                                            </div>
                                        </div>

                                        <p className="text-muted-foreground hidden text-sm md:block md:text-foreground">
                                            {recurrence.schedule_label}
                                        </p>
                                        <p className="text-muted-foreground hidden text-sm md:block md:text-foreground">
                                            {recurrence.next_occurrence
                                                ? formatDate(
                                                      recurrence.next_occurrence,
                                                  )
                                                : 'Sem próxima data'}
                                        </p>
                                        <p className="text-muted-foreground hidden truncate text-sm md:block md:text-foreground">
                                            {recurrence.category_name ??
                                                'Sem categoria'}
                                        </p>
                                        <p
                                            className={`text-right text-sm font-semibold tabular-nums ${
                                                isExpense
                                                    ? 'text-destructive'
                                                    : 'text-positive'
                                            }`}
                                        >
                                            {isExpense ? '− ' : '+ '}
                                            {currency.format(
                                                Number(recurrence.amount),
                                            )}
                                        </p>
                                        <ArrowRight className="text-muted-foreground hidden size-4 shrink-0 transition-transform group-hover:translate-x-0.5 md:block" />
                                    </Link>
                                );
                            })}
                        </div>
                    </Card>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

RecurrencesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Recorrências',
            href: index(),
        },
    ],
};
