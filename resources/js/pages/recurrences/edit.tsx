import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Pause, Play } from 'lucide-react';
import FinancialRecurrenceController from '@/actions/App/Http/Controllers/FinancialRecurrenceController';
import FinancialRecurrenceForm from '@/components/recurrences/financial-recurrence-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/recurrences';
import type {
    FinancialEntryReferenceOption,
    FinancialRecurrence,
    PaymentMethodOption,
    RecurrenceOption,
} from '@/types';

type Props = {
    recurrence: FinancialRecurrence;
    accountOptions: FinancialEntryReferenceOption[];
    cardOptions: FinancialEntryReferenceOption[];
    categoryOptions: FinancialEntryReferenceOption[];
    memberOptions: FinancialEntryReferenceOption[];
    paymentMethods: PaymentMethodOption[];
    frequencyOptions: RecurrenceOption[];
    typeOptions: RecurrenceOption[];
};

export default function RecurrenceEdit({ recurrence, ...formProps }: Props) {
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
                            Ajuste a regra. Compromissos futuros ainda
                            planejados são regenerados. Se o início da geração
                            recuar, as ocorrências faltantes desse período
                            também são criadas.
                        </p>
                    </div>
                    <Form
                        {...FinancialRecurrenceController.toggleStatus.form(
                            recurrence.id,
                        )}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant="outline"
                                disabled={processing}
                            >
                                {recurrence.is_active ? <Pause /> : <Play />}
                                {recurrence.is_active ? 'Pausar' : 'Ativar'}
                            </Button>
                        )}
                    </Form>
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
