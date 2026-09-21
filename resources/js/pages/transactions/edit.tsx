import { Head, Link } from '@inertiajs/react';
import { Repeat2 } from 'lucide-react';
import FinancialEntryForm from '@/components/transactions/financial-entry-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { edit as editRecurrence } from '@/routes/recurrences';
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
                    <CardContent className="space-y-6">
                        {entry.financial_recurrence_id !== null && (
                            <div className="border-primary/20 bg-primary/5 rounded-lg border p-3 text-sm">
                                <p className="flex items-center gap-2 font-medium">
                                    <Repeat2 className="text-primary size-4" />
                                    Ocorrência de uma recorrência
                                </p>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Alterações aqui valem somente para esta
                                    ocorrência. Para mudar as próximas,{' '}
                                    <Link
                                        className="text-primary font-medium underline-offset-4 hover:underline"
                                        href={editRecurrence(
                                            entry.financial_recurrence_id,
                                        )}
                                    >
                                        edite a recorrência
                                    </Link>
                                    .
                                </p>
                            </div>
                        )}
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
