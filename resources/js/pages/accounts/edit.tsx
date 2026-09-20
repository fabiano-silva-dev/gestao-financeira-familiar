import { Head } from '@inertiajs/react';
import AccountForm from '@/components/financial-accounts/account-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/accounts';
import type { FinancialAccount, FinancialAccountTypeOption } from '@/types';

export default function AccountsEdit({
    account,
    accountTypes,
}: {
    account: FinancialAccount;
    accountTypes: FinancialAccountTypeOption[];
}) {
    return (
        <>
            <Head title={`Editar ${account.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar conta
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Atualize os dados de {account.name}.
                    </p>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Dados da conta</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <AccountForm
                            account={account}
                            accountTypes={accountTypes}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AccountsEdit.layout = {
    breadcrumbs: [
        { title: 'Contas financeiras', href: index() },
        { title: 'Editar conta', href: index() },
    ],
};
