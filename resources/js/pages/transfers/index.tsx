import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    CalendarDays,
    Check,
    Pencil,
    Plus,
    RotateCcw,
    X,
} from 'lucide-react';
import TransferController from '@/actions/App/Http/Controllers/TransferController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { create, edit, index } from '@/routes/transfers';
import type { Transfer } from '@/types';

type Props = {
    transfers: Transfer[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const date = new Intl.DateTimeFormat('pt-BR', {
    timeZone: 'UTC',
});

function statusVariant(status: Transfer['status']) {
    if (status === 'confirmed') {
        return 'secondary' as const;
    }

    return 'outline' as const;
}

function statusAction(transfer: Transfer) {
    if (transfer.status === 'planned') {
        return { label: 'Confirmar', icon: Check };
    }

    if (transfer.status === 'confirmed') {
        return { label: 'Cancelar', icon: X };
    }

    return { label: 'Reativar', icon: RotateCcw };
}

export default function TransfersIndex() {
    const { transfers, workspace } = usePage<Props>().props;

    return (
        <>
            <Head title="Transferências" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Transferências
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Movimentações internas de{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>{' '}
                            sem impacto em receitas ou despesas.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Nova transferência
                        </Link>
                    </Button>
                </div>

                {transfers.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <ArrowLeftRight className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhuma transferência cadastrada
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Registre movimentações entre suas contas sem
                                    duplicar receitas ou despesas.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>
                                    Cadastrar primeira transferência
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-3">
                        {transfers.map((transfer) => {
                            const action = statusAction(transfer);
                            const StatusIcon = action.icon;

                            return (
                                <Card
                                    key={transfer.id}
                                    className={
                                        transfer.status === 'cancelled'
                                            ? 'opacity-65'
                                            : undefined
                                    }
                                >
                                    <CardHeader>
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="min-w-0">
                                                <CardTitle className="truncate">
                                                    {transfer.description}
                                                </CardTitle>
                                                <div className="text-muted-foreground mt-1 flex items-center gap-1 text-xs">
                                                    <CalendarDays className="size-3.5" />
                                                    {date.format(
                                                        new Date(
                                                            `${transfer.transaction_date}T00:00:00Z`,
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    variant={statusVariant(
                                                        transfer.status,
                                                    )}
                                                >
                                                    {transfer.status_label}
                                                </Badge>
                                                <p className="text-lg font-semibold tabular-nums">
                                                    {currency.format(
                                                        Number(transfer.amount),
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="bg-muted/50 flex flex-col gap-2 rounded-lg p-3 text-sm sm:flex-row sm:items-center sm:justify-center">
                                            <span className="font-medium">
                                                {transfer.source_account_name}
                                            </span>
                                            <ArrowLeftRight className="text-muted-foreground size-4 rotate-90 sm:rotate-0" />
                                            <span className="font-medium">
                                                {
                                                    transfer.destination_account_name
                                                }
                                            </span>
                                        </div>
                                        {transfer.notes && (
                                            <p className="text-muted-foreground mt-3 text-sm">
                                                {transfer.notes}
                                            </p>
                                        )}
                                    </CardContent>
                                    <CardFooter className="flex flex-wrap justify-end gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={edit(transfer.id)}>
                                                <Pencil />
                                                Editar
                                            </Link>
                                        </Button>
                                        <Form
                                            {...TransferController.advanceStatus.form(
                                                transfer.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    <StatusIcon />
                                                    {action.label}
                                                </Button>
                                            )}
                                        </Form>
                                    </CardFooter>
                                </Card>
                            );
                        })}
                    </div>
                )}
            </div>
        </>
    );
}

TransfersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Transferências',
            href: index(),
        },
    ],
};
