import { Head } from '@inertiajs/react';
import FinancialRecurrenceForm from '@/components/recurrences/financial-recurrence-form';
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

export default function RecurrenceEdit({
    recurrence,
    ...formProps
}: Props) {
    return (
        <>
            <Head title="Editar recorrência" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar recorrência
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Ajuste a regra; compromissos futuros ainda planejados
                        serão regenerados com os novos dados.
                    </p>
                </div>

                <Card className="max-w-4xl">
                    <CardHeader>
                        <CardTitle>{recurrence.description}</CardTitle>
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
