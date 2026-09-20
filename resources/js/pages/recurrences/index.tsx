import { Form, Head, Link } from '@inertiajs/react';
import {
    CalendarClock,
    Repeat2,
    CircleArrowDown,
    CircleArrowUp,
    Pause,
    Pencil,
    Play,
    Plus,
} from 'lucide-react';
import FinancialRecurrenceController from '@/actions/App/Http/Controllers/FinancialRecurrenceController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { create, edit, index } from '@/routes/recurrences';
import type {
    FinancialRecurrence,
    RecurrenceProjectionPoint,
} from '@/types';

type Props = {
    recurrences: FinancialRecurrence[];
    projection: RecurrenceProjectionPoint[];
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
    return date.format(new Date(value + 'T00:00:00Z'));
}

export default function RecurrencesIndex({
    recurrences,
    projection,
}: Props) {
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
                            Cadastre uma vez e trabalhe apenas nos pagamentos e
                            exceções.
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
                    <CardHeader>
                        <CardTitle>Projeção recorrente — próximos 6 meses</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {projection.map((month) => {
                                const net = Number(month.net);

                                return (
                                    <div
                                        key={month.month}
                                        className="rounded-lg border p-4"
                                    >
                                        <p className="text-muted-foreground text-xs font-medium uppercase">
                                            {monthYear.format(
                                                new Date(
                                                    month.month +
                                                        'T00:00:00Z',
                                                ),
                                            )}
                                        </p>
                                        <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
                                            <div>
                                                <p className="text-muted-foreground text-xs">
                                                    Receitas
                                                </p>
                                                <p className="text-positive font-semibold tabular-nums">
                                                    {currency.format(
                                                        Number(month.income),
                                                    )}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-muted-foreground text-xs">
                                                    Despesas
                                                </p>
                                                <p className="text-destructive font-semibold tabular-nums">
                                                    {currency.format(
                                                        Number(month.expenses),
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="mt-3 border-t pt-3">
                                            <p className="text-muted-foreground text-xs">
                                                Resultado recorrente
                                            </p>
                                            <p
                                                className={
                                                    net < 0
                                                        ? 'text-destructive font-semibold tabular-nums'
                                                        : 'font-semibold tabular-nums'
                                                }
                                            >
                                                {currency.format(net)}
                                            </p>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {recurrences.length === 0 ? (
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
                    <div className="space-y-3">
                        {recurrences.map((recurrence) => {
                            const isExpense =
                                recurrence.type === 'expense';
                            const TypeIcon = isExpense
                                ? CircleArrowDown
                                : CircleArrowUp;

                            return (
                                <Card
                                    key={recurrence.id}
                                    className={
                                        recurrence.is_active
                                            ? undefined
                                            : 'opacity-65'
                                    }
                                >
                                    <CardHeader>
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="flex min-w-0 gap-3">
                                                <div className="bg-muted mt-0.5 rounded-full p-2">
                                                    <TypeIcon
                                                        className={
                                                            isExpense
                                                                ? 'text-destructive size-5'
                                                                : 'text-positive size-5'
                                                        }
                                                    />
                                                </div>
                                                <div className="min-w-0">
                                                    <CardTitle className="truncate">
                                                        {
                                                            recurrence.description
                                                        }
                                                    </CardTitle>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {
                                                            recurrence.schedule_label
                                                        }
                                                        {' · '}
                                                        inicia em{' '}
                                                        {formatDate(
                                                            recurrence.starts_on,
                                                        )}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2 sm:flex-col sm:items-end">
                                                <p
                                                    className={
                                                        isExpense
                                                            ? 'text-destructive text-lg font-semibold tabular-nums'
                                                            : 'text-positive text-lg font-semibold tabular-nums'
                                                    }
                                                >
                                                    {isExpense ? '− ' : '+ '}
                                                    {currency.format(
                                                        Number(
                                                            recurrence.amount,
                                                        ),
                                                    )}
                                                </p>
                                                <div className="flex gap-2">
                                                    <Badge variant="outline">
                                                        {
                                                            recurrence.payment_method_label
                                                        }
                                                    </Badge>
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
                                            </div>
                                        </div>
                                    </CardHeader>

                                    <CardContent className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Conta ou cartão
                                            </p>
                                            <p className="font-medium">
                                                {recurrence.credit_card_name ??
                                                    recurrence.financial_account_name ??
                                                    'Não informado'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Categoria
                                            </p>
                                            <p className="font-medium">
                                                {recurrence.category_name ??
                                                    'Sem categoria'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Pessoa
                                            </p>
                                            <p className="font-medium">
                                                {recurrence.family_member_name ??
                                                    'Não informada'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Próxima ocorrência
                                            </p>
                                            <p className="font-medium">
                                                {recurrence.next_occurrence
                                                    ? formatDate(
                                                          recurrence.next_occurrence,
                                                      )
                                                    : 'Sem próxima data'}
                                            </p>
                                        </div>

                                        {(recurrence.payee_name ||
                                            recurrence.payment_instructions) && (
                                            <div className="bg-muted/50 rounded-lg p-3 sm:col-span-2 lg:col-span-4">
                                                <p className="font-medium">
                                                    {recurrence.payee_name ??
                                                        'Instruções de pagamento'}
                                                </p>
                                                {recurrence.payment_instructions && (
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {
                                                            recurrence.payment_instructions
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                        )}

                                        <div className="text-muted-foreground flex items-center gap-2 sm:col-span-2 lg:col-span-4">
                                            <CalendarClock className="size-4" />
                                            {
                                                recurrence.generated_transactions_count
                                            }{' '}
                                            lançamento(s) já gerado(s) a partir
                                            desta regra.
                                        </div>
                                    </CardContent>

                                    <div className="flex flex-wrap justify-end gap-2 border-t px-6 py-4">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={edit(recurrence.id)}>
                                                <Pencil />
                                                Editar
                                            </Link>
                                        </Button>
                                        <Form
                                            {...FinancialRecurrenceController.toggleStatus.form(
                                                recurrence.id,
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
                                                    {recurrence.is_active ? (
                                                        <Pause />
                                                    ) : (
                                                        <Play />
                                                    )}
                                                    {recurrence.is_active
                                                        ? 'Pausar'
                                                        : 'Ativar'}
                                                </Button>
                                            )}
                                        </Form>
                                    </div>
                                </Card>
                            );
                        })}
                    </div>
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
