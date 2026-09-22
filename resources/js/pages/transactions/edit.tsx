import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { EntryOriginBanner } from '@/components/transactions/entry-origin-banner';
import EntryTypeSwitcher from '@/components/transactions/entry-type-switcher';
import FinancialEntryForm from '@/components/transactions/financial-entry-form';
import TransferForm from '@/components/transfers/transfer-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/transactions';
import type {
    FinancialEntry,
    FinancialEntryReferenceOption,
    FinancialEntryType,
    PaymentMethodOption,
} from '@/types';

type TypeOption = {
    value: string;
    label: string;
};

type Props = {
    entry: FinancialEntry;
    typeOptions: TypeOption[];
    accountOptions: FinancialEntryReferenceOption[];
    cardOptions: FinancialEntryReferenceOption[];
    categoryOptions: FinancialEntryReferenceOption[];
    memberOptions: FinancialEntryReferenceOption[];
    paymentMethods: PaymentMethodOption[];
};

export default function TransactionsEdit({
    entry,
    typeOptions,
    accountOptions,
    ...formProps
}: Props) {
    const [type, setType] = useState<FinancialEntryType>(entry.type);
    const isTransfer = type === 'transfer';
    const typeLabel =
        typeOptions.find((option) => option.value === type)?.label ??
        entry.type_label;

    return (
        <>
            <Head title={`Editar ${typeLabel.toLocaleLowerCase('pt-BR')}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar {typeLabel.toLocaleLowerCase('pt-BR')}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {isTransfer
                            ? 'Atualize a movimentação entre contas próprias.'
                            : 'Atualize o lançamento e seu impacto financeiro.'}
                    </p>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Dados do lançamento</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <EntryOriginBanner entry={entry} />
                        <EntryTypeSwitcher
                            value={type}
                            options={typeOptions}
                            onChange={setType}
                        />
                        {isTransfer ? (
                            <TransferForm
                                transfer={{
                                    id: entry.id,
                                    transaction_date: entry.transaction_date,
                                    description: entry.description,
                                    amount: entry.amount,
                                    source_account_id:
                                        entry.source_account_id ??
                                        entry.financial_account_id ??
                                        0,
                                    source_account_name:
                                        entry.source_account_name ??
                                        entry.financial_account_name ??
                                        '',
                                    destination_account_id:
                                        entry.destination_account_id ?? 0,
                                    destination_account_name:
                                        entry.destination_account_name ?? '',
                                    status: entry.status,
                                    status_label: entry.status_label,
                                    notes: entry.notes,
                                }}
                                accountOptions={accountOptions}
                            />
                        ) : (
                            <FinancialEntryForm
                                entry={entry}
                                entryType={type}
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

TransactionsEdit.layout = {
    breadcrumbs: [
        { title: 'Lançamentos', href: index() },
        { title: 'Editar lançamento', href: index() },
    ],
};
