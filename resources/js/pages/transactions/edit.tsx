import { Head } from '@inertiajs/react';
import FinancialEntryForm from '@/components/transactions/financial-entry-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/transactions';
import type {
    FinancialEntry,
    FinancialEntryReferenceOption,
    PaymentMethodOption,
} from '@/types';

type Props = {
    entry: FinancialEntry;
    accountOptions: FinancialEntryReferenceOption[];
    cardOptions: FinancialEntryReferenceOption[];
    categoryOptions: FinancialEntryReferenceOption[];
    memberOptions: FinancialEntryReferenceOption[];
    paymentMethods: PaymentMethodOption[];
};

export default function TransactionsEdit({ entry, ...formProps }: Props) {
    return (
        <>
            <Head title={`Editar ${entry.description}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar {entry.type_label.toLocaleLowerCase('pt-BR')}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Atualize o lançamento e seu impacto financeiro.
                    </p>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Dados do lançamento</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <FinancialEntryForm entry={entry} {...formProps} />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

TransactionsEdit.layout = {
    breadcrumbs: [
        { title: 'Lançamentos', href: index() },
        { title: 'Editar lançamento', href: index() },
    ],
};
