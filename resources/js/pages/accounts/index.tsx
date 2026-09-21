import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Landmark, Pencil, Plus, Power } from 'lucide-react';
import FinancialAccountController from '@/actions/App/Http/Controllers/FinancialAccountController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, edit, index, show } from '@/routes/accounts';
import type { FinancialAccount, FinancialAccountSummary } from '@/types';

type Props = {
    accounts: FinancialAccount[];
    summary: FinancialAccountSummary;
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const date = new Intl.DateTimeFormat('pt-BR', {
    timeZone: 'UTC',
});

function formatDate(value: string) {
    return date.format(new Date(value + 'T00:00:00Z'));
}

export default function AccountsIndex() {
    const { accounts, summary, workspace } = usePage<Props>().props;

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
                            Onde o dinheiro e as reservas do workspace{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>{' '}
                            estão guardados.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Nova conta
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-5">
                        <p className="text-muted-foreground text-xs uppercase">
                            Saldo total
                        </p>
                        <p className="mt-1 text-3xl font-semibold tabular-nums">
                            {currency.format(Number(summary.total_balance))}
                        </p>
                        <p className="text-muted-foreground mt-1 text-xs">
                            Soma de {summary.active_accounts}{' '}
                            {summary.active_accounts === 1
                                ? 'conta ativa'
                                : 'contas ativas'}
                        </p>
                    </CardContent>
                </Card>

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
                                    Cadastre a primeira conta bancária, carteira,
                                    reserva ou conta de investimento para começar
                                    o controle.
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
                    <div className="space-y-3">
                        {accounts.map((account) => (
                            <Card
                                key={account.id}
                                className={
                                    account.is_active ? undefined : 'opacity-70'
                                }
                            >
                                <CardContent className="p-0">
                                    <div className="flex flex-col lg:flex-row">
                                        <Link
                                            href={show(account.id)}
                                            className="group flex min-w-0 flex-1 flex-col gap-4 p-5 lg:flex-row lg:items-center"
                                        >
                                            <div className="flex min-w-0 flex-1 items-start gap-3">
                                                <div className="bg-muted rounded-full p-2.5">
                                                    <Landmark className="size-5" />
                                                </div>
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="truncate font-semibold">
                                                            {account.name}
                                                        </p>
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
                                                    <p className="text-muted-foreground mt-1 text-sm">
                                                        {account.institution ||
                                                            'Sem instituição'}{' '}
                                                        · {account.type_label}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="grid flex-1 grid-cols-2 gap-4 sm:grid-cols-3">
                                                <div>
                                                    <p className="text-muted-foreground text-xs uppercase">
                                                        Saldo atual
                                                    </p>
                                                    <p className="font-semibold tabular-nums">
                                                        {currency.format(
                                                            Number(account.current_balance),
                                                        )}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground text-xs uppercase">
                                                        Saldo inicial
                                                    </p>
                                                    <p className="font-medium tabular-nums">
                                                        {currency.format(
                                                            Number(account.opening_balance),
                                                        )}
                                                    </p>
                                                </div>
                                                <div className="col-span-2 sm:col-span-1">
                                                    <p className="text-muted-foreground text-xs uppercase">
                                                        Data inicial
                                                    </p>
                                                    <p className="font-medium">
                                                        {account.opening_balance_date
                                                            ? formatDate(
                                                                  account.opening_balance_date,
                                                              )
                                                            : 'Não informada'}
                                                    </p>
                                                </div>
                                            </div>

                                            <ArrowRight className="text-muted-foreground hidden size-5 shrink-0 transition-transform group-hover:translate-x-1 lg:block" />
                                        </Link>

                                        <div className="flex items-center justify-end gap-2 border-t p-3 lg:border-t-0 lg:border-l">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={edit(account.id)}>
                                                    <Pencil />
                                                    Editar
                                                </Link>
                                            </Button>
                                            <Form
                                                {...FinancialAccountController.toggleStatus.form(
                                                    account.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
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
                                        </div>
                                    </div>
                                </CardContent>
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
