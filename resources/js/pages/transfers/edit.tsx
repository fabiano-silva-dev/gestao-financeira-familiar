import { Head } from '@inertiajs/react';
import TransferForm from '@/components/transfers/transfer-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/transfers';
import type { Transfer, TransferAccountOption } from '@/types';

type Props = {
    transfer: Transfer;
    accountOptions: TransferAccountOption[];
};

export default function TransfersEdit({ transfer, accountOptions }: Props) {
    return (
        <>
            <Head title={`Editar ${transfer.description}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar transferência
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        A saída e a entrada vinculadas serão atualizadas juntas.
                    </p>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Dados da transferência</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <TransferForm
                            transfer={transfer}
                            accountOptions={accountOptions}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

TransfersEdit.layout = {
    breadcrumbs: [
        { title: 'Transferências', href: index() },
        { title: 'Editar transferência', href: index() },
    ],
};
