import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    Check,
    CircleArrowDown,
    CircleArrowUp,
    Pencil,
    Plus,
    ReceiptText,
    RotateCcw,
    X,
} from 'lucide-react';
import FinancialTransactionController from '@/actions/App/Http/Controllers/FinancialTransactionController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    createExpense,
    createIncome,
    edit,
    index,
} from '@/routes/transactions';
import type { FinancialEntry } from '@/types';

type Props = {
    entries: FinancialEntry[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const date = new Intl.DateTimeFormat('pt-BR', {
    timeZone: 'UTC',
});

function formatDate(value: string) {
    return date.format(new Date(`${value}T00:00:00Z`));
}

function statusAction(entry: FinancialEntry) {
    if (entry.status === 'planned') {
        return { label: 'Confirmar', icon: Check };
    }

    if (entry.status === 'confirmed') {
        return { label: 'Cancelar', icon: X };
    }

    return { label: 'Reativar', icon: RotateCcw };
}

export default function TransactionsIndex() {
    const { entries, workspace } = usePage<Props>().props;

    return (
        <>
            <Head title="Lançamentos" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Receitas e despesas
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Lançamentos realizados e futuros de{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>
                            .
                        </p>
                    </div>

                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Button variant="outline" asChild>
                            <Link href={createIncome()}>
                                <CircleArrowUp />
                                Nova receita
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

                {entries.length === 0 ? (
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
                                    Registre a primeira receita ou despesa para
                                    iniciar o acompanhamento financeiro.
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
                    <div className="space-y-3">
                        {entries.map((entry) => {
                            const isExpense = entry.type === 'expense';
                            const action = statusAction(entry);
                            const StatusIcon = action.icon;
                            const TypeIcon = isExpense
                                ? CircleArrowDown
                                : CircleArrowUp;

                            return (
                                <Card
                                    key={entry.id}
                                    className={
                                        entry.status === 'cancelled'
                                            ? 'opacity-65'
                                            : undefined
                                    }
                                >
                                    <CardHeader>
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="flex min-w-0 gap-3">
                                                <div className="bg-muted mt-0.5 rounded-full p-2">
                                                    <TypeIcon
                                                        className={`size-5 ${
                                                            isExpense
                                                                ? 'text-destructive'
                                                                : 'text-positive'
                                                        }`}
                                                    />
                                                </div>
                                                <div className="min-w-0">
                                                    <CardTitle className="truncate">
                                                        {entry.description}
                                                    </CardTitle>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {formatDate(
                                                            entry.transaction_date,
                                                        )}
                                                        {entry.category_name
                                                            ? ` · ${entry.category_name}`
                                                            : ''}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2 sm:flex-col sm:items-end">
                                                <p
                                                    className={`text-lg font-semibold tabular-nums ${
                                                        isExpense
                                                            ? 'text-destructive'
                                                            : 'text-positive'
                                                    }`}
                                                >
                                                    {isExpense ? '−' : '+'}{' '}
                                                    {currency.format(
                                                        Number(entry.amount),
                                                    )}
                                                </p>
                                                <div className="flex gap-2">
                                                    <Badge
                                                        variant={
                                                            isExpense
                                                                ? 'destructive'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {entry.type_label}
                                                    </Badge>
                                                    <Badge variant="outline">
                                                        {entry.status_label}
                                                    </Badge>
                                                </div>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                {isExpense
                                                    ? 'Pagamento'
                                                    : 'Recebimento'}
                                            </p>
                                            <p className="font-medium">
                                                {entry.payment_method_label}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Conta ou cartão
                                            </p>
                                            <p className="font-medium">
                                                {entry.credit_card_name ??
                                                    entry.financial_account_name ??
                                                    'Não informado'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Pessoa
                                            </p>
                                            <p className="font-medium">
                                                {entry.family_member_name ??
                                                    'Não informada'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Vencimento
                                            </p>
                                            <p className="font-medium">
                                                {entry.due_date ? (
                                                    formatDate(entry.due_date)
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Não informado
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                        {(entry.payee_name ||
                                            entry.payment_instructions) && (
                                            <div className="bg-muted/50 rounded-lg p-3 sm:col-span-2 lg:col-span-4">
                                                <p className="font-medium">
                                                    {entry.payee_name ??
                                                        'Instruções de pagamento'}
                                                </p>
                                                {entry.payment_instructions && (
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {
                                                            entry.payment_instructions
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                        {entry.status === 'planned' && (
                                            <div className="text-muted-foreground flex items-center gap-2 sm:col-span-2 lg:col-span-4">
                                                <CalendarClock className="size-4" />
                                                Compromisso futuro; ainda não
                                                altera o saldo da conta.
                                            </div>
                                        )}
                                    </CardContent>
                                    <CardFooter className="flex flex-wrap justify-end gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={edit(entry.id)}>
                                                <Pencil />
                                                Editar
                                            </Link>
                                        </Button>
                                        <Form
                                            {...FinancialTransactionController.advanceStatus.form(
                                                entry.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    <StatusIcon />
                                                    {action.label}
                                                </Button>
                                            )}
                                        </Form>
                                    </CardFooter>
                                </Card>
                            );
                        })}
                    </div>
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
