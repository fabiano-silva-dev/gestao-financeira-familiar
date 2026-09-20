import { Head } from '@inertiajs/react';
import AccountForm from '@/components/financial-accounts/account-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { create, index } from '@/routes/accounts';
import type { FinancialAccountTypeOption } from '@/types';

export default function AccountsCreate({
    accountTypes,
}: {
    accountTypes: FinancialAccountTypeOption[];
}) {
    return (
        <>
            <Head title="Nova conta" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Nova conta
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Cadastre onde o dinheiro da família está guardado.
                    </p>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Dados da conta</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <AccountForm accountTypes={accountTypes} />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AccountsCreate.layout = {
    breadcrumbs: [
        { title: 'Contas financeiras', href: index() },
        { title: 'Nova conta', href: create() },
    ],
};
