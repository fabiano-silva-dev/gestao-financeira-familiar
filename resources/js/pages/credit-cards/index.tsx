import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CreditCard as CreditCardIcon,
    Pencil,
    Plus,
    Power,
} from 'lucide-react';
import CreditCardController from '@/actions/App/Http/Controllers/CreditCardController';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { sortListing } from '@/lib/listing';
import { create, edit, index, show } from '@/routes/credit-cards';
import type {
    CreditCard,
    CreditCardSummary,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    cards: CreditCard[];
    summary: CreditCardSummary;
    filters: ListingQueryState;
    hasRecords: boolean;
    statusOptions: ListingFilterOption[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const rowGridClass =
    'md:grid-cols-[minmax(0,1.5fr)_minmax(7rem,0.6fr)_minmax(7rem,0.6fr)_minmax(7rem,0.6fr)_minmax(7rem,0.6fr)_minmax(12rem,0.9fr)]';

export default function CreditCardsIndex() {
    const { cards, summary, filters, hasRecords, statusOptions, workspace } =
        usePage<Props>().props;
    const listUrl = index.url();
    const onSort = (column: string) =>
        sortListing(
            listUrl,
            filters,
            column,
            column === 'name' || column === 'status' ? 'asc' : 'desc',
        );

    return (
        <>
            <Head title="Cartões" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Cartões de crédito
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Limites e compromissos dos cartões de{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>
                            .
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Novo cartão
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Limite total
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(summary.total_limit))}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Somente cartões ativos
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Limite utilizado
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(summary.used_limit))}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Compras e parcelas ainda comprometidas
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Limite disponível
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(
                                    Number(summary.available_limit),
                                )}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Total menos o valor utilizado
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {!hasRecords ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <CreditCardIcon className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhum cartão cadastrado
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Cadastre um cartão para controlar compras,
                                    parcelas e faturas.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>
                                    Cadastrar primeiro cartão
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <ListingToolbar
                            url={listUrl}
                            query={filters}
                            searchPlaceholder="Buscar cartão, instituição ou final…"
                            selects={[
                                {
                                    key: 'status',
                                    label: 'Situação',
                                    value: filters.status,
                                    options: statusOptions,
                                    allLabel: 'Todos',
                                },
                            ]}
                        />

                        {cards.length === 0 ? (
                            <ListingEmpty />
                        ) : (
                            <Card className="gap-0 overflow-hidden py-0">
                                <div
                                    className={`text-muted-foreground hidden gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid ${rowGridClass}`}
                                >
                                    <SortableColumn
                                        column="name"
                                        label="Cartão"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="limit"
                                        label="Limite"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                        align="right"
                                    />
                                    <SortableColumn
                                        column="used"
                                        label="Usado"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                        align="right"
                                    />
                                    <SortableColumn
                                        column="available"
                                        label="Disponível"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                        align="right"
                                    />
                                    <SortableColumn
                                        column="status"
                                        label="Situação"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <span className="text-right">Ações</span>
                                </div>
                                <div className="divide-y">
                                    {cards.map((card) => (
                                        <div
                                            key={card.id}
                                            className={`grid grid-cols-1 gap-3 px-4 py-3 md:items-center md:gap-3 ${rowGridClass} ${
                                                card.is_active
                                                    ? ''
                                                    : 'opacity-70'
                                            }`}
                                        >
                                            <Link
                                                href={show(card.id)}
                                                className="hover:text-primary group flex min-w-0 items-start gap-3"
                                            >
                                                <div className="bg-muted rounded-full p-2">
                                                    <CreditCardIcon className="size-4" />
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {card.name}
                                                    </p>
                                                    <p className="text-muted-foreground mt-0.5 truncate text-xs">
                                                        {card.institution ||
                                                            'Sem instituição'}{' '}
                                                        · final {card.last_four}
                                                    </p>
                                                </div>
                                                <ArrowRight className="text-muted-foreground mt-1 hidden size-4 shrink-0 transition-transform group-hover:translate-x-0.5 md:block" />
                                            </Link>
                                            <p className="hidden text-right text-sm font-medium tabular-nums md:block">
                                                {currency.format(
                                                    Number(card.credit_limit),
                                                )}
                                            </p>
                                            <p className="hidden text-right text-sm tabular-nums md:block">
                                                {currency.format(
                                                    Number(card.used_limit),
                                                )}
                                            </p>
                                            <p className="text-right text-sm font-semibold tabular-nums">
                                                {currency.format(
                                                    Number(
                                                        card.available_limit,
                                                    ),
                                                )}
                                            </p>
                                            <Badge
                                                variant={
                                                    card.is_active
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                className="w-fit"
                                            >
                                                {card.is_active
                                                    ? 'Ativo'
                                                    : 'Inativo'}
                                            </Badge>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={edit(card.id)}
                                                    >
                                                        <Pencil />
                                                        Editar
                                                    </Link>
                                                </Button>
                                                <Form
                                                    {...CreditCardController.toggleStatus.form(
                                                        card.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            <Power />
                                                            {card.is_active
                                                                ? 'Desativar'
                                                                : 'Ativar'}
                                                        </Button>
                                                    )}
                                                </Form>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </Card>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

CreditCardsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Cartões',
            href: index(),
        },
    ],
};
