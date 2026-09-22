import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Landmark, Pencil, Plus, Power } from 'lucide-react';
import FinancialAccountController from '@/actions/App/Http/Controllers/FinancialAccountController';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { sortListing } from '@/lib/listing';
import { create, edit, index, show } from '@/routes/accounts';
import type {
    FinancialAccount,
    FinancialAccountSummary,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    accounts: FinancialAccount[];
    summary: FinancialAccountSummary;
    filters: ListingQueryState;
    hasRecords: boolean;
    typeOptions: ListingFilterOption[];
    statusOptions: ListingFilterOption[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const rowGridClass =
    'md:grid-cols-[minmax(0,1.6fr)_minmax(8rem,0.7fr)_minmax(7rem,0.6fr)_minmax(8rem,0.7fr)_minmax(12rem,0.9fr)]';

export default function AccountsIndex() {
    const {
        accounts,
        summary,
        filters,
        hasRecords,
        typeOptions,
        statusOptions,
        workspace,
    } = usePage<Props>().props;
    const listUrl = index.url();
    const onSort = (column: string) =>
        sortListing(
            listUrl,
            filters,
            column,
            column === 'balance' ? 'desc' : 'asc',
        );

    return (
        <>
            <Head title="Contas financeiras" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Contas financeiras
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Onde o dinheiro e as reservas do workspace{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>{' '}
                            estão guardados.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Nova conta
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-5">
                        <p className="text-muted-foreground text-xs uppercase">
                            Saldo total
                        </p>
                        <p className="mt-1 text-3xl font-semibold tabular-nums">
                            {currency.format(Number(summary.total_balance))}
                        </p>
                        <p className="text-muted-foreground mt-1 text-xs">
                            Soma de {summary.active_accounts}{' '}
                            {summary.active_accounts === 1
                                ? 'conta ativa'
                                : 'contas ativas'}
                        </p>
                    </CardContent>
                </Card>

                {!hasRecords ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <Landmark className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhuma conta cadastrada
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Cadastre a primeira conta bancária,
                                    carteira, reserva ou conta de investimento
                                    para começar o controle.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>
                                    Cadastrar primeira conta
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <ListingToolbar
                            url={listUrl}
                            query={filters}
                            searchPlaceholder="Buscar conta, instituição, agência ou número…"
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
                            ]}
                        />

                        {accounts.length === 0 ? (
                            <ListingEmpty />
                        ) : (
                            <Card className="gap-0 overflow-hidden py-0">
                                <div
                                    className={`text-muted-foreground hidden gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid ${rowGridClass}`}
                                >
                                    <SortableColumn
                                        column="name"
                                        label="Conta"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="type"
                                        label="Tipo"
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
                                        column="balance"
                                        label="Saldo"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                        align="right"
                                    />
                                    <span className="text-right">Ações</span>
                                </div>
                                <div className="divide-y">
                                    {accounts.map((account) => (
                                        <div
                                            key={account.id}
                                            className={`grid grid-cols-1 gap-3 px-4 py-3 md:items-center md:gap-3 ${rowGridClass} ${
                                                account.is_active
                                                    ? ''
                                                    : 'opacity-70'
                                            }`}
                                        >
                                            <Link
                                                href={show(account.id)}
                                                className="hover:text-primary group flex min-w-0 items-start gap-3"
                                            >
                                                <div className="bg-muted rounded-full p-2">
                                                    <Landmark className="size-4" />
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {account.name}
                                                    </p>
                                                    <p className="text-muted-foreground mt-0.5 truncate text-xs">
                                                        {[
                                                            account.institution ||
                                                                'Sem instituição',
                                                            account.agency
                                                                ? `Ag. ${account.agency}`
                                                                : null,
                                                            account.account_number
                                                                ? `Conta ${account.account_number}`
                                                                : null,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </p>
                                                </div>
                                                <ArrowRight className="text-muted-foreground mt-1 hidden size-4 shrink-0 transition-transform group-hover:translate-x-0.5 md:block" />
                                            </Link>
                                            <p className="text-muted-foreground hidden text-sm md:block md:text-foreground">
                                                {account.type_label}
                                            </p>
                                            <Badge
                                                variant={
                                                    account.is_active
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                className="w-fit"
                                            >
                                                {account.is_active
                                                    ? 'Ativa'
                                                    : 'Inativa'}
                                            </Badge>
                                            <p className="text-right text-sm font-semibold tabular-nums">
                                                {currency.format(
                                                    Number(
                                                        account.current_balance,
                                                    ),
                                                )}
                                            </p>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={edit(account.id)}
                                                    >
                                                        <Pencil />
                                                        Editar
                                                    </Link>
                                                </Button>
                                                <Form
                                                    {...FinancialAccountController.toggleStatus.form(
                                                        account.id,
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
                                                            {account.is_active
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

AccountsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Contas financeiras',
            href: index(),
        },
    ],
};
