import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ChevronRight, Pause, Play, Trash2 } from 'lucide-react';
import { useState } from 'react';
import FinancialRecurrenceController from '@/actions/App/Http/Controllers/FinancialRecurrenceController';
import FinancialRecurrenceForm from '@/components/recurrences/financial-recurrence-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/recurrences';
import type {
    FinancialEntryReferenceOption,
    FinancialRecurrence,
    FinancialRecurrenceOccurrence,
    PaymentMethodOption,
    RecurrenceOption,
} from '@/types';

type Props = {
    recurrence: FinancialRecurrence;
    occurrences: FinancialRecurrenceOccurrence[];
    accountOptions: FinancialEntryReferenceOption[];
    cardOptions: FinancialEntryReferenceOption[];
    categoryOptions: FinancialEntryReferenceOption[];
    memberOptions: FinancialEntryReferenceOption[];
    paymentMethods: PaymentMethodOption[];
    frequencyOptions: RecurrenceOption[];
    typeOptions: RecurrenceOption[];
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

function formatDate(value: string | null) {
    return value ? date.format(new Date(`${value}T00:00:00Z`)) : '—';
}

function OccurrenceRows({
    occurrences,
}: {
    occurrences: FinancialRecurrenceOccurrence[];
}) {
    if (occurrences.length === 0) {
        return (
            <p className="text-muted-foreground rounded-lg border border-dashed p-4 text-sm">
                Nenhuma ocorrência neste grupo.
            </p>
        );
    }

    return (
        <div className="overflow-hidden rounded-lg border">
            {occurrences.map((occurrence, index) => (
                <div
                    key={occurrence.id}
                    className={[
                        'flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between',
                        index > 0 ? 'border-t' : '',
                    ].join(' ')}
                >
                    <div className="min-w-0 space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">
                                {formatDate(occurrence.occurrence_date)}
                            </span>
                            <Badge
                                variant={
                                    occurrence.status === 'cancelled'
                                        ? 'destructive'
                                        : occurrence.settled_on
                                          ? 'secondary'
                                          : 'outline'
                                }
                            >
                                {occurrence.status_label}
                            </Badge>
                            {occurrence.is_reconciled && (
                                <Badge variant="outline">Conciliado</Badge>
                            )}
                            {occurrence.is_overridden && (
                                <Badge variant="outline">
                                    Ajustado manualmente
                                </Badge>
                            )}
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {occurrence.account_name ?? 'Sem conta definida'}
                            {occurrence.settled_on
                                ? ` · realizado em ${formatDate(occurrence.settled_on)}`
                                : occurrence.due_date
                                  ? ` · vencimento ${formatDate(occurrence.due_date)}`
                                  : ''}
                        </p>
                    </div>

                    <div className="flex items-center justify-between gap-4 sm:justify-end">
                        <span className="font-semibold">
                            {currency.format(Number(occurrence.amount))}
                        </span>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={`/lancamentos/${occurrence.id}/editar`}>
                                Abrir lançamento
                                <ChevronRight />
                            </Link>
                        </Button>
                    </div>
                </div>
            ))}
        </div>
    );
}

export default function RecurrenceEdit({
    recurrence,
    occurrences,
    ...formProps
}: Props) {
    const [deleting, setDeleting] = useState(false);
    const realized = occurrences.filter(
        (occurrence) => occurrence.settled_on !== null,
    );
    const open = occurrences.filter(
        (occurrence) => occurrence.settled_on === null,
    );

    const deleteRecurrence = () => {
        const confirmed = window.confirm(
            'Excluir esta recorrência? As ocorrências futuras ainda planejadas serão removidas. Lançamentos já pagos, recebidos, conciliados ou ajustados serão preservados no histórico.',
        );

        if (!confirmed) {
            return;
        }

        router.delete(`/recorrencias/${recurrence.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <>
            <Head title={recurrence.description} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="mb-2"
                            asChild
                        >
                            <Link href={index()}>
                                <ArrowLeft />
                                Voltar para a lista
                            </Link>
                        </Button>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {recurrence.description}
                            </h1>
                            <Badge
                                variant={
                                    recurrence.is_active
                                        ? 'secondary'
                                        : 'outline'
                                }
                            >
                                {recurrence.is_active ? 'Ativa' : 'Pausada'}
                            </Badge>
                        </div>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Ajuste a regra e consulte os lançamentos que ela já
                            gerou.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Form
                            {...FinancialRecurrenceController.toggleStatus.form(
                                recurrence.id,
                            )}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    variant="outline"
                                    disabled={processing || deleting}
                                >
                                    {recurrence.is_active ? <Pause /> : <Play />}
                                    {recurrence.is_active
                                        ? 'Pausar'
                                        : 'Ativar'}
                                </Button>
                            )}
                        </Form>
                        <Button
                            variant="destructive"
                            type="button"
                            onClick={deleteRecurrence}
                            disabled={deleting}
                        >
                            <Trash2 />
                            {deleting ? 'Excluindo...' : 'Excluir recorrência'}
                        </Button>
                    </div>
                </div>

                <Card className="max-w-4xl">
                    <CardHeader>
                        <CardTitle>Dados da recorrência</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <FinancialRecurrenceForm
                            recurrence={recurrence}
                            {...formProps}
                        />
                    </CardContent>
                </Card>

                <Card className="max-w-4xl">
                    <CardHeader>
                        <CardTitle>
                            Ocorrências geradas ({occurrences.length})
                        </CardTitle>
                        <p className="text-muted-foreground text-sm">
                            Os lançamentos realizados permanecem no histórico
                            mesmo se a recorrência for excluída.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <section className="space-y-3">
                            <div>
                                <h2 className="font-medium">
                                    Realizadas ({realized.length})
                                </h2>
                                <p className="text-muted-foreground text-xs">
                                    Pagamentos e recebimentos que já afetaram o
                                    caixa.
                                </p>
                            </div>
                            <OccurrenceRows occurrences={realized} />
                        </section>

                        <section className="space-y-3">
                            <div>
                                <h2 className="font-medium">
                                    Em aberto e futuras ({open.length})
                                </h2>
                                <p className="text-muted-foreground text-xs">
                                    Compromissos materializados pela regra e
                                    ainda não liquidados.
                                </p>
                            </div>
                            <OccurrenceRows occurrences={open} />
                        </section>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

RecurrenceEdit.layout = {
    breadcrumbs: [
        { title: 'Recorrências', href: index() },
        { title: 'Editar recorrência', href: index() },
    ],
};
