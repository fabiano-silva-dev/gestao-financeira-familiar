import { Head, Link } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import TransferForm from '@/components/transfers/transfer-form';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { create as createAccount } from '@/routes/accounts';
import { create, index } from '@/routes/transfers';
import type { TransferAccountOption } from '@/types';

type Props = {
    accountOptions: TransferAccountOption[];
    defaultDate: string;
};

export default function TransfersCreate({
    accountOptions,
    defaultDate,
}: Props) {
    const hasEnoughAccounts = accountOptions.length >= 2;

    return (
        <>
            <Head title="Nova transferência" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Nova transferência
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Movimente dinheiro entre duas contas da família.
                    </p>
                </div>

                {hasEnoughAccounts ? (
                    <Card className="max-w-3xl">
                        <CardHeader>
                            <CardTitle>Dados da transferência</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <TransferForm
                                accountOptions={accountOptions}
                                defaultDate={defaultDate}
                            />
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="max-w-2xl border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <Landmark className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Cadastre pelo menos duas contas
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Uma transferência precisa de uma conta de
                                    origem e outra de destino.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={createAccount()}>
                                    Cadastrar conta
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

TransfersCreate.layout = {
    breadcrumbs: [
        { title: 'Transferências', href: index() },
        { title: 'Nova transferência', href: create() },
    ],
};
