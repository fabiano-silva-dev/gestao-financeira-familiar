import { Head, Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import {
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowRight,
    ArrowUpRight,
    CheckCircle2,
    Landmark,
    Pencil,
    Plus,
    ReceiptText,
    RotateCcw,
} from 'lucide-react';
import { MonthSelector } from '@/components/dashboard/month-selector';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit, index, show } from '@/routes/accounts';
import { show as showInvoice } from '@/routes/credit-card-invoices';
import { sortListing } from '@/lib/listing';
import { edit as editTransaction } from '@/routes/transactions';
import type {
    FinancialAccount,
    FinancialAccountMovementOverview,
    FinancialAccountPanoramaSummary,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    account: FinancialAccount;
    summary: FinancialAccountPanoramaSummary;
    movements: FinancialAccountMovementOverview[];
    periodClosure: {
        status: 'closed' | 'no_movement';
        closed_at: string | null;
    } | null;
    currentPeriod: string;
    filters: ListingQueryState;
    hasRecords: boolean;
    typeOptions: ListingFilterOption[];
    quickEntryAccountOptions: Array<{
        id: number;
        name: string;
    }>;
};

type QuickEntryType = 'expense' | 'income' | 'transfer';
type QuickTransferDirection = 'out' | 'in';

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

const monthYear = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const rowGridClass =
    'md:grid-cols-[minmax(7rem,0.55fr)_minmax(0,1.7fr)_minmax(9rem,0.8fr)_minmax(8rem,0.7fr)_1.25rem]';

function formatDate(value: string) {
    return date.format(new Date(`${value}T00:00:00Z`));
}

function formatMonth(value: string) {
    return monthYear.format(new Date(`${value}T00:00:00Z`));
}

function localToday() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function movementHref(movement: FinancialAccountMovementOverview) {
    if (movement.transaction_id !== null) {
        return editTransaction(movement.transaction_id);
    }

    if (movement.invoice_id !== null) {
        return showInvoice(movement.invoice_id);
    }

    return null;
}

function movementSubtitle(movement: FinancialAccountMovementOverview) {
    const parts = [movement.type_label];

    if (movement.category_name) {
        parts.push(movement.category_name);
    }

    if (movement.counterparty_account_name) {
        parts.push(movement.counterparty_account_name);
    }

    if (movement.credit_card_name) {
        parts.push(movement.credit_card_name);
    }

    if (movement.family_member_name) {
        parts.push(movement.family_member_name);
    }

    return parts.join(' · ');
}

export default function AccountShow() {
    const {
        account,
        summary,
        movements,
        periodClosure,
        currentPeriod,
        filters,
        hasRecords,
        typeOptions,
        quickEntryAccountOptions,
    } = usePage<Props>().props;
    const listUrl = show.url(account.id);
    const periodLabel = formatMonth(currentPeriod);
    const period = currentPeriod.slice(0, 7);
    const entryQuery = new URLSearchParams({
        account: String(account.id),
        period,
    }).toString();
    const periodIsClosed = periodClosure !== null;
    const today = localToday();
    const defaultQuickEntryDate = today.slice(0, 7) === period
        ? today
        : currentPeriod;
    const [quickEntryOpen, setQuickEntryOpen] = useState(false);
    const [quickEntryType, setQuickEntryType] = useState<QuickEntryType>('expense');
    const [quickEntryDate, setQuickEntryDate] = useState(defaultQuickEntryDate);
    const [quickEntryDescription, setQuickEntryDescription] = useState('');
    const [quickEntryAmount, setQuickEntryAmount] = useState('');
    const [quickTransferDirection, setQuickTransferDirection] =
        useState<QuickTransferDirection>('out');
    const [quickTransferAccountId, setQuickTransferAccountId] = useState(
        quickEntryAccountOptions[0] ? String(quickEntryAccountOptions[0].id) : '',
    );
    const [quickEntryProcessing, setQuickEntryProcessing] = useState(false);
    const [quickEntryErrors, setQuickEntryErrors] = useState<Record<string, string>>({});

    const toggleQuickEntry = () => {
        setQuickEntryOpen((open) => {
            if (!open) {
                setQuickEntryDate(defaultQuickEntryDate);
                setQuickEntryErrors({});
            }

            return !open;
        });
    };

    const submitQuickEntry = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setQuickEntryErrors({});

        const common = {
            transaction_date: quickEntryDate,
            description: quickEntryDescription.trim(),
            amount: quickEntryAmount,
            status: 'confirmed',
            _return_account: account.id,
            _return_period: period,
        };
        const options = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setQuickEntryProcessing(true),
            onError: (errors: Record<string, string>) => setQuickEntryErrors(errors),
            onSuccess: () => {
                setQuickEntryDescription('');
                setQuickEntryAmount('');
            },
            onFinish: () => setQuickEntryProcessing(false),
        };

        if (quickEntryType === 'transfer') {
            if (quickTransferAccountId === '') {
                setQuickEntryErrors({
                    destination_account_id: 'Selecione a outra conta da transferência.',
                });
                return;
            }

            const counterpartyId = Number(quickTransferAccountId);

            router.post(
                '/transferencias',
                {
                    ...common,
                    source_account_id:
                        quickTransferDirection === 'out' ? account.id : counterpartyId,
                    destination_account_id:
                        quickTransferDirection === 'out' ? counterpartyId : account.id,
                },
                options,
            );

            return;
        }

        router.post(
            '/lancamentos',
            {
                ...common,
                type: quickEntryType,
                financial_account_id: account.id,
                payment_method: 'other',
                installment_count: 1,
            },
            options,
        );
    };

    const togglePeriodClosure = () => {
        if (periodIsClosed) {
            router.delete(
                `/importacoes/fechamento-mensal/account/${account.id}/reabrir`,
                {
                    data: { period, return_to_account: true },
                    preserveScroll: true,
                },
            );

            return;
        }

        router.post(
            `/importacoes/fechamento-mensal/account/${account.id}/fechar`,
            { period, return_to_account: true },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={account.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-start gap-3">
                        <div className="bg-muted rounded-full p-3">
                            <Landmark className="size-6" />
                        </div>
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {account.name}
                                </h1>
                                <Badge
                                    variant={
                                        account.is_active
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {account.is_active ? 'Ativa' : 'Inativa'}
                                </Badge>
                            </div>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {[
                                    account.institution || 'Sem instituição',
                                    account.agency
                                        ? `Ag. ${account.agency}`
                                        : null,
                                    account.account_number
                                        ? `Conta ${account.account_number}`
                                        : null,
                                    account.type_label,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
                        <MonthSelector
                            currentPeriod={currentPeriod}
                            url={listUrl}
                            query={filters}
                        />
                        <Button
                            type="button"
                            onClick={toggleQuickEntry}
                        >
                            <Plus />
                            Lançamento rápido
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline">
                                    <Plus />
                                    Novo lançamento
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem asChild>
                                    <Link
                                        href={`/lancamentos/nova-despesa?${entryQuery}`}
                                    >
                                        Despesa
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <Link
                                        href={`/lancamentos/nova-receita?${entryQuery}`}
                                    >
                                        Receita
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <Link
                                        href={`/lancamentos/nova-transferencia?${entryQuery}`}
                                    >
                                        Transferência entre contas
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={togglePeriodClosure}
                        >
                            {periodIsClosed ? <RotateCcw /> : <CheckCircle2 />}
                            {periodIsClosed
                                ? 'Reabrir mês'
                                : 'Marcar mês conferido'}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={edit(account.id)}>
                                <Pencil />
                                Editar conta
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Saldo inicial
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(
                                    Number(summary.period_opening_balance),
                                )}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                No início de {periodLabel}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-5">
                            <div className="flex items-center gap-2">
                                <ArrowDownLeft className="text-muted-foreground size-4" />
                                <p className="text-muted-foreground text-xs uppercase">
                                    Entradas
                                </p>
                            </div>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(summary.inflows))}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Em {periodLabel}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-5">
                            <div className="flex items-center gap-2">
                                <ArrowUpRight className="text-muted-foreground size-4" />
                                <p className="text-muted-foreground text-xs uppercase">
                                    Saídas
                                </p>
                            </div>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(summary.outflows))}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Em {periodLabel}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Saldo final
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(
                                    Number(summary.period_closing_balance),
                                )}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                No fim de {periodLabel}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="gap-1">
                        <CardTitle className="flex items-center gap-2">
                            <ReceiptText className="size-5" />
                            Extrato da conta
                        </CardTitle>
                        <p className="text-muted-foreground text-sm">
                            {summary.movement_count}{' '}
                            {summary.movement_count === 1
                                ? 'lançamento efetivo'
                                : 'lançamentos efetivos'}{' '}
                            em {periodLabel},{' '}
                            {filters.direction === 'desc'
                                ? 'do dia mais recente para o mais antigo.'
                                : 'do dia mais antigo para o mais recente.'}
                        </p>
                    </CardHeader>

                    <CardContent className="space-y-4">
                        {quickEntryOpen && (
                            <form
                                onSubmit={submitQuickEntry}
                                className="bg-muted/20 space-y-4 rounded-lg border p-4"
                            >
                                <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p className="font-medium">Lançamento rápido</p>
                                        <p className="text-muted-foreground text-xs">
                                            Registre um movimento efetivo sem sair do extrato.
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setQuickEntryOpen(false)}
                                    >
                                        Fechar
                                    </Button>
                                </div>

                                {Object.keys(quickEntryErrors).length > 0 && (
                                    <div className="text-destructive rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm">
                                        {Object.values(quickEntryErrors)[0]}
                                    </div>
                                )}

                                <div className="grid gap-3 md:grid-cols-[10rem_12rem_minmax(14rem,1fr)_11rem_auto] md:items-end">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="quick-entry-date">Data</Label>
                                        <Input
                                            id="quick-entry-date"
                                            type="date"
                                            value={quickEntryDate}
                                            onChange={(event) => setQuickEntryDate(event.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Tipo</Label>
                                        <Select
                                            value={quickEntryType}
                                            onValueChange={(value) =>
                                                setQuickEntryType(value as QuickEntryType)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="expense">Despesa</SelectItem>
                                                <SelectItem value="income">Receita</SelectItem>
                                                <SelectItem value="transfer">Transferência</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="quick-entry-description">Histórico</Label>
                                        <Input
                                            id="quick-entry-description"
                                            value={quickEntryDescription}
                                            onChange={(event) => setQuickEntryDescription(event.target.value)}
                                            maxLength={160}
                                            placeholder="Descrição do lançamento"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="quick-entry-amount">Valor</Label>
                                        <Input
                                            id="quick-entry-amount"
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            value={quickEntryAmount}
                                            onChange={(event) => setQuickEntryAmount(event.target.value)}
                                            placeholder="0,00"
                                            required
                                        />
                                    </div>
                                    <Button type="submit" disabled={quickEntryProcessing}>
                                        {quickEntryProcessing ? 'Salvando…' : 'Confirmar'}
                                    </Button>
                                </div>

                                {quickEntryType === 'transfer' && (
                                    <div className="grid gap-3 border-t pt-4 md:grid-cols-[12rem_minmax(14rem,24rem)]">
                                        <div className="space-y-1.5">
                                            <Label>Movimento nesta conta</Label>
                                            <Select
                                                value={quickTransferDirection}
                                                onValueChange={(value) =>
                                                    setQuickTransferDirection(
                                                        value as QuickTransferDirection,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="out">Saída desta conta</SelectItem>
                                                    <SelectItem value="in">Entrada nesta conta</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Outra conta</Label>
                                            {quickEntryAccountOptions.length > 0 ? (
                                                <Select
                                                    value={quickTransferAccountId}
                                                    onValueChange={setQuickTransferAccountId}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Selecione a conta" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {quickEntryAccountOptions.map((option) => (
                                                            <SelectItem
                                                                key={option.id}
                                                                value={String(option.id)}
                                                            >
                                                                {option.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <div className="text-muted-foreground flex h-9 items-center rounded-md border px-3 text-sm">
                                                    Nenhuma outra conta ativa disponível.
                                                </div>
                                            )}
                                        </div>
                                        <div className="text-muted-foreground flex items-end gap-2 text-xs md:col-span-2">
                                            <ArrowLeftRight className="size-4" />
                                            A transferência movimenta as duas contas e não cria receita ou despesa.
                                        </div>
                                    </div>
                                )}
                            </form>
                        )}

                        {hasRecords && (
                            <ListingToolbar
                                url={listUrl}
                                query={filters}
                                searchPlaceholder="Buscar lançamento…"
                                selects={[
                                    {
                                        key: 'type',
                                        label: 'Tipo',
                                        value: filters.type,
                                        options: typeOptions,
                                        allLabel: 'Todos',
                                    },
                                ]}
                            />
                        )}

                        {movements.length === 0 ? (
                            hasRecords ? (
                                <ListingEmpty description="Nenhum lançamento neste período." />
                            ) : (
                                <div className="flex flex-col items-center gap-3 py-10 text-center">
                                    <div className="bg-muted rounded-full p-3">
                                        <ReceiptText className="text-muted-foreground size-5" />
                                    </div>
                                    <div>
                                        <p className="font-medium">
                                            Nenhum lançamento no período
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            O saldo do mês ainda corresponde ao
                                            saldo inicial informado.
                                        </p>
                                    </div>
                                </div>
                            )
                        ) : (
                            <div className="overflow-hidden rounded-lg border">
                                <div
                                    className={`text-muted-foreground hidden gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid ${rowGridClass}`}
                                >
                                    <SortableColumn
                                        column="date"
                                        label="Data"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={(column) =>
                                            sortListing(
                                                listUrl,
                                                filters,
                                                column,
                                                'asc',
                                            )
                                        }
                                    />
                                    <span>Lançamento</span>
                                    <span>Tipo</span>
                                    <span className="text-right">Valor</span>
                                    <span className="sr-only">Abrir</span>
                                </div>
                                <div className="divide-y">
                                    {movements.map((movement) => {
                                        const href = movementHref(movement);
                                        const isInflow =
                                            Number(movement.amount) >= 0;
                                        const rowClassName = `hover:bg-muted/40 focus-visible:ring-ring group grid grid-cols-1 gap-2 px-4 py-3 transition-colors focus-visible:ring-2 focus-visible:outline-none md:items-center md:gap-3 ${rowGridClass}`;

                                        const content = (
                                            <>
                                                <p className="text-muted-foreground hidden text-sm md:block md:text-foreground">
                                                    {formatDate(
                                                        movement.occurred_on,
                                                    )}
                                                </p>
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="truncate font-medium">
                                                            {
                                                                movement.description
                                                            }
                                                        </p>
                                                        {movement.is_reconciled && (
                                                            <Badge variant="secondary">
                                                                Conciliado
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <p className="text-muted-foreground mt-1 truncate text-xs">
                                                        <span className="md:hidden">
                                                            {formatDate(
                                                                movement.occurred_on,
                                                            )}
                                                            {' · '}
                                                        </span>
                                                        {movementSubtitle(
                                                            movement,
                                                        )}
                                                    </p>
                                                </div>
                                                <p className="text-muted-foreground hidden truncate text-sm md:block md:text-foreground">
                                                    {movement.type_label}
                                                </p>
                                                <p
                                                    className={`text-right text-lg font-semibold tabular-nums ${
                                                        isInflow
                                                            ? 'text-positive'
                                                            : 'text-destructive'
                                                    }`}
                                                >
                                                    {isInflow ? '+' : ''}
                                                    {currency.format(
                                                        Number(movement.amount),
                                                    )}
                                                </p>
                                                {href ? (
                                                    <ArrowRight className="text-muted-foreground hidden size-4 shrink-0 transition-transform group-hover:translate-x-0.5 md:block" />
                                                ) : (
                                                    <span className="hidden md:block" />
                                                )}
                                            </>
                                        );

                                        if (href === null) {
                                            return (
                                                <div
                                                    key={movement.id}
                                                    className={rowClassName}
                                                >
                                                    {content}
                                                </div>
                                            );
                                        }

                                        return (
                                            <Link
                                                key={movement.id}
                                                href={href}
                                                className={rowClassName}
                                            >
                                                {content}
                                            </Link>
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

AccountShow.layout = {
    breadcrumbs: [
        {
            title: 'Contas financeiras',
            href: index(),
        },
    ],
};
