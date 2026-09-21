import { Head } from '@inertiajs/react';
import FinancialRecurrenceForm from '@/components/recurrences/financial-recurrence-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/recurrences';
import type {
    FinancialEntryReferenceOption,
    PaymentMethodOption,
    RecurrenceOption,
} from '@/types';

type Props = {
    defaultStartDate: string;
    accountOptions: FinancialEntryReferenceOption[];
    cardOptions: FinancialEntryReferenceOption[];
    categoryOptions: FinancialEntryReferenceOption[];
    memberOptions: FinancialEntryReferenceOption[];
    paymentMethods: PaymentMethodOption[];
    frequencyOptions: RecurrenceOption[];
    typeOptions: RecurrenceOption[];
};

export default function RecurrenceCreate(props: Props) {
    return (
        <>
            <Head title="Nova recorrência" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Nova recorrência
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Defina uma receita ou despesa que se repete e deixe o
                        sistema preparar os próximos compromissos.
                    </p>
                </div>

                <Card className="max-w-4xl">
                    <CardHeader>
                        <CardTitle>Dados da recorrência</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <FinancialRecurrenceForm {...props} />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

RecurrenceCreate.layout = {
    breadcrumbs: [
        { title: 'Recorrências', href: index() },
        { title: 'Nova recorrência', href: index() },
    ],
};
