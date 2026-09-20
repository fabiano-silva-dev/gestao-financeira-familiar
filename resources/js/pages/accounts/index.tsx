import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Landmark, Pencil, Plus, Power } from 'lucide-react';
import FinancialAccountController from '@/actions/App/Http/Controllers/FinancialAccountController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { create, edit, index } from '@/routes/accounts';
import type { FinancialAccount } from '@/types';

type Props = {
    accounts: FinancialAccount[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

export default function AccountsIndex() {
    const { accounts, workspace } = usePage<Props>().props;

    return (
        <>
            <Head title="Contas financeiras" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Contas financeiras
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Onde o dinheiro do workspace{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>{' '}
                            está guardado.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Nova conta
                        </Link>
                    </Button>
                </div>

                {accounts.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <Landmark className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhuma conta cadastrada
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Cadastre a primeira conta bancária, carteira
                                    ou conta digital para começar o controle.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>
                                    Cadastrar primeira conta
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {accounts.map((account) => (
                            <Card
                                key={account.id}
                                className={
                                    account.is_active ? undefined : 'opacity-70'
                                }
                            >
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <CardTitle className="truncate">
                                                {account.name}
                                            </CardTitle>
                                            <CardDescription>
                                                {account.institution ||
                                                    'Sem instituição informada'}
                                            </CardDescription>
                                        </div>
                                        <Badge
                                            variant={
                                                account.is_active
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {account.is_active
                                                ? 'Ativa'
                                                : 'Inativa'}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Tipo
                                        </p>
                                        <p className="text-sm font-medium">
                                            {account.type_label}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Saldo inicial
                                        </p>
                                        <p className="text-xl font-semibold tabular-nums">
                                            {currency.format(
                                                Number(account.opening_balance),
                                            )}
                                        </p>
                                    </div>
                                </CardContent>
                                <CardFooter className="flex flex-wrap justify-end gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={edit(account.id)}>
                                            <Pencil />
                                            Editar
                                        </Link>
                                    </Button>
                                    <Form
                                        {...FinancialAccountController.toggleStatus.form(
                                            account.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                <Power />
                                                {account.is_active
                                                    ? 'Desativar'
                                                    : 'Ativar'}
                                            </Button>
                                        )}
                                    </Form>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

AccountsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Contas financeiras',
            href: index(),
        },
    ],
};
