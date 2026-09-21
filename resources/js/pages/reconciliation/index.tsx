import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowDownCircle,
    ArrowUpCircle,
    BadgeCheck,
    CircleAlert,
    History,
    Link2,
    ListChecks,
    Plus,
    RotateCcw,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import BankReconciliationController from '@/actions/App/Http/Controllers/BankReconciliationController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/reconciliation';
import { createExpense, createIncome } from '@/routes/transactions';
import type {
    ReconciliationHistoryItem,
    ReconciliationPendingEntry,
} from '@/types';

type Props = {
    entries: ReconciliationPendingEntry[];
    history: ReconciliationHistoryItem[];
    pendingEntriesCount: number;
    unmatchedMovementsCount: number;
    reconciledEntriesCount: number;
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

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

function formatDate(value: string) {
    return date.format(new Date(`${value}T00:00:00Z`));
}

function confidenceVariant(confidence: 'high' | 'medium' | 'low') {
    if (confidence === 'high') return 'secondary' as const;
    return 'outline' as const;
}

export default function ReconciliationIndex({
    entries,
    history,
    pendingEntriesCount,
    unmatchedMovementsCount,
    reconciledEntriesCount,
}: Props) {
    const [selectedMovements, setSelectedMovements] = useState<
        Record<number, string>
    >(() =>
        entries.reduce<Record<number, string>>((selected, entry) => {
            const suggestion = entry.candidates.find(
                (candidate) => candidate.is_suggestion,
            );

            if (suggestion) {
                selected[entry.id] = String(suggestion.movement_id);
            }

            return selected;
        }, {}),
    );

    return (
        <>
            <Head title="Conciliação bancária" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                        Fase 6 · Conferência
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Conciliação bancária
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Ligue o que aconteceu no banco ao lançamento já
                        registrado, sem alterar o caixa duas vezes.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>OFX pendentes</CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {pendingEntriesCount}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>
                                Lançamentos sem vínculo
                            </CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {unmatchedMovementsCount}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Já conciliados</CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {reconciledEntriesCount}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <ListChecks className="size-5" />
                            Pendências do extrato
                        </CardTitle>
                        <CardDescription>
                            O sistema compara conta, valor, data e descrição.
                            Você confirma a correspondência antes do vínculo.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {entries.length === 0 ? (
                            <div className="flex flex-col items-center gap-3 py-10 text-center">
                                <BadgeCheck className="text-positive size-9" />
                                <p className="font-medium">
                                    Tudo conciliado por aqui
                                </p>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Importe um novo OFX quando quiser conferir
                                    os próximos movimentos da conta.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {entries.map((entry) => {
                                    const amount = Number(entry.amount);
                                    const isDebit = amount < 0;
                                    const AmountIcon = isDebit
                                        ? ArrowDownCircle
                                        : ArrowUpCircle;
                                    const suggestion = entry.candidates.find(
                                        (candidate) => candidate.is_suggestion,
                                    );
                                    const selected =
                                        selectedMovements[entry.id] ?? '';

                                    return (
                                        <div
                                            key={entry.id}
                                            className="rounded-xl border p-4"
                                        >
                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div className="flex min-w-0 items-start gap-3">
                                                    <AmountIcon
                                                        className={
                                                            isDebit
                                                                ? 'text-destructive mt-0.5 size-5 shrink-0'
                                                                : 'text-positive mt-0.5 size-5 shrink-0'
                                                        }
                                                    />
                                                    <div className="min-w-0">
                                                        <p className="truncate font-medium">
                                                            {entry.description}
                                                        </p>
                                                        <p className="text-muted-foreground mt-1 text-xs">
                                                            {entry.account_name}{' '}
                                                            ·{' '}
                                                            {formatDate(
                                                                entry.occurred_on,
                                                            )}
                                                            {entry.transaction_type
                                                                ? ` · ${entry.transaction_type}`
                                                                : ''}
                                                        </p>
                                                        {entry.memo &&
                                                            entry.memo !==
                                                                entry.description && (
                                                                <p className="text-muted-foreground mt-1 line-clamp-1 text-xs">
                                                                    {entry.memo}
                                                                </p>
                                                            )}
                                                    </div>
                                                </div>
                                                <p
                                                    className={
                                                        isDebit
                                                            ? 'text-destructive font-semibold tabular-nums'
                                                            : 'text-positive font-semibold tabular-nums'
                                                    }
                                                >
                                                    {currency.format(amount)}
                                                </p>
                                            </div>

                                            {suggestion && (
                                                <div className="bg-primary/5 border-primary/15 mt-4 rounded-lg border p-3">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Sparkles className="text-primary size-4" />
                                                        <p className="text-sm font-medium">
                                                            Melhor sugestão
                                                        </p>
                                                        <Badge
                                                            variant={confidenceVariant(
                                                                suggestion.confidence,
                                                            )}
                                                        >
                                                            {
                                                                suggestion.confidence_label
                                                            }
                                                        </Badge>
                                                        <span className="text-muted-foreground text-xs">
                                                            {suggestion.score}%
                                                        </span>
                                                    </div>
                                                    <p className="mt-2 text-sm">
                                                        {suggestion.description}
                                                    </p>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {suggestion.type_label}{' '}
                                                        ·{' '}
                                                        {formatDate(
                                                            suggestion.occurred_on,
                                                        )}
                                                        {suggestion.date_distance ===
                                                        0
                                                            ? ' · mesma data'
                                                            : ` · diferença de ${suggestion.date_distance} dia(s)`}
                                                    </p>
                                                </div>
                                            )}

                                            {entry.candidates.length > 0 ? (
                                                <Form
                                                    {...BankReconciliationController.store.form(
                                                        entry.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                    className="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start"
                                                >
                                                    {({
                                                        processing,
                                                        errors,
                                                    }) => (
                                                        <>
                                                            <div>
                                                                <input
                                                                    type="hidden"
                                                                    name="account_movement_id"
                                                                    value={
                                                                        selected
                                                                    }
                                                                />
                                                                <Select
                                                                    value={
                                                                        selected
                                                                    }
                                                                    onValueChange={(
                                                                        value,
                                                                    ) =>
                                                                        setSelectedMovements(
                                                                            (
                                                                                current,
                                                                            ) => ({
                                                                                ...current,
                                                                                [entry.id]:
                                                                                    value,
                                                                            }),
                                                                        )
                                                                    }
                                                                >
                                                                    <SelectTrigger className="w-full">
                                                                        <SelectValue placeholder="Selecione um lançamento compatível" />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        {entry.candidates.map(
                                                                            (
                                                                                candidate,
                                                                            ) => (
                                                                                <SelectItem
                                                                                    key={
                                                                                        candidate.movement_id
                                                                                    }
                                                                                    value={String(
                                                                                        candidate.movement_id,
                                                                                    )}
                                                                                >
                                                                                    {
                                                                                        candidate.description
                                                                                    }{' '}
                                                                                    ·{' '}
                                                                                    {formatDate(
                                                                                        candidate.occurred_on,
                                                                                    )}{' '}
                                                                                    ·{' '}
                                                                                    {
                                                                                        candidate.type_label
                                                                                    }
                                                                                </SelectItem>
                                                                            ),
                                                                        )}
                                                                    </SelectContent>
                                                                </Select>
                                                                <InputError
                                                                    message={
                                                                        errors.account_movement_id
                                                                    }
                                                                    className="mt-2"
                                                                />
                                                            </div>
                                                            <Button
                                                                disabled={
                                                                    processing ||
                                                                    selected ===
                                                                        ''
                                                                }
                                                            >
                                                                <Link2 />
                                                                Conciliar
                                                            </Button>
                                                        </>
                                                    )}
                                                </Form>
                                            ) : (
                                                <div className="mt-4 flex flex-col gap-3 rounded-lg border border-dashed p-3 sm:flex-row sm:items-center sm:justify-between">
                                                    <div className="flex items-start gap-2">
                                                        <CircleAlert className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                                                        <div>
                                                            <p className="text-sm font-medium">
                                                                Nenhum
                                                                lançamento com a
                                                                mesma conta e
                                                                valor
                                                            </p>
                                                            <p className="text-muted-foreground mt-1 text-xs">
                                                                Cadastre o fato
                                                                financeiro e
                                                                volte para
                                                                confirmar o
                                                                vínculo.
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={
                                                                isDebit
                                                                    ? createExpense()
                                                                    : createIncome()
                                                            }
                                                        >
                                                            <Plus />
                                                            {isDebit
                                                                ? 'Nova despesa'
                                                                : 'Nova receita'}
                                                        </Link>
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <History className="size-5" />
                            Histórico recente
                        </CardTitle>
                        <CardDescription>
                            As últimas conciliações podem ser desfeitas para
                            correção.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {history.length === 0 ? (
                            <p className="text-muted-foreground py-6 text-center text-sm">
                                Nenhuma conciliação registrada até agora.
                            </p>
                        ) : (
                            <div className="divide-y">
                                {history.map((item) => (
                                    <div
                                        key={item.id}
                                        className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {item.bank_description}
                                            </p>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {item.account_name} · banco em{' '}
                                                {formatDate(
                                                    item.bank_occurred_on,
                                                )}
                                            </p>
                                            <p className="mt-2 text-sm">
                                                <span className="text-muted-foreground">
                                                    Vinculado a:{' '}
                                                </span>
                                                {item.movement_description ??
                                                    'Lançamento removido'}
                                                {item.movement_type_label
                                                    ? ` · ${item.movement_type_label}`
                                                    : ''}
                                                {item.movement_occurred_on
                                                    ? ` · ${formatDate(item.movement_occurred_on)}`
                                                    : ''}
                                            </p>
                                            {item.reconciled_at && (
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    Conciliado em{' '}
                                                    {dateTime.format(
                                                        new Date(
                                                            item.reconciled_at,
                                                        ),
                                                    )}
                                                    {item.reconciled_by_name
                                                        ? ` por ${item.reconciled_by_name}`
                                                        : ''}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex items-center justify-between gap-3 sm:justify-end">
                                            <p className="font-semibold tabular-nums">
                                                {currency.format(
                                                    Number(item.amount),
                                                )}
                                            </p>
                                            <Form
                                                {...BankReconciliationController.destroy.form(
                                                    item.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        <RotateCcw />
                                                        Desfazer
                                                    </Button>
                                                )}
                                            </Form>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
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
