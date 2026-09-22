import { Head } from '@inertiajs/react';
import FinancialEntryForm from '@/components/transactions/financial-entry-form';
import TransferForm from '@/components/transfers/transfer-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/transactions';
import type {
    FinancialEntryReferenceOption,
    FinancialEntryType,
    PaymentMethodOption,
} from '@/types';

type Props = {
    entryType: FinancialEntryType;
    entryTypeLabel: string;
    defaultDate: string;
    accountOptions: FinancialEntryReferenceOption[];
    cardOptions: FinancialEntryReferenceOption[];
    categoryOptions: FinancialEntryReferenceOption[];
    memberOptions: FinancialEntryReferenceOption[];
    paymentMethods: PaymentMethodOption[];
};

export default function TransactionsCreate({
    entryType,
    entryTypeLabel,
    defaultDate,
    accountOptions,
    ...formProps
}: Props) {
    const isTransfer = entryType === 'transfer';
    const isExpense = entryType === 'expense';

    return (
        <>
            <Head title={`Nova ${entryTypeLabel.toLocaleLowerCase('pt-BR')}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Nova {entryTypeLabel.toLocaleLowerCase('pt-BR')}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {isTransfer
                            ? 'Movimente saldo entre contas próprias, sem criar receita ou despesa.'
                            : isExpense
                              ? 'Registre o gasto, o vencimento e como ele será pago.'
                              : 'Registre a entrada realizada ou prevista.'}
                    </p>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Dados do lançamento</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {isTransfer ? (
                            <TransferForm
                                accountOptions={accountOptions}
                                defaultDate={defaultDate}
                            />
                        ) : (
                            <FinancialEntryForm
                                entryType={entryType}
                                defaultDate={defaultDate}
                                accountOptions={accountOptions}
                                {...formProps}
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

TransactionsCreate.layout = {
    breadcrumbs: [
        { title: 'Lançamentos', href: index() },
        { title: 'Novo lançamento', href: index() },
    ],
};
