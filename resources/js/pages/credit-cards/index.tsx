import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CreditCard as CreditCardIcon,
    Pencil,
    Plus,
    Power,
} from 'lucide-react';
import CreditCardController from '@/actions/App/Http/Controllers/CreditCardController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, edit, index, show } from '@/routes/credit-cards';
import type { CreditCard, CreditCardSummary } from '@/types';

type Props = {
    cards: CreditCard[];
    summary: CreditCardSummary;
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

export default function CreditCardsIndex() {
    const { cards, summary, workspace } = usePage<Props>().props;

    return (
        <>
            <Head title="Cartões" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Cartões de crédito
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Limites e compromissos dos cartões de{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>
                            .
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Novo cartão
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Limite total
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(summary.total_limit))}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Somente cartões ativos
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Limite utilizado
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(summary.used_limit))}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Compras e parcelas ainda comprometidas
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-xs uppercase">
                                Limite disponível
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {currency.format(Number(summary.available_limit))}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Total menos o valor utilizado
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {cards.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <CreditCardIcon className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhum cartão cadastrado
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Cadastre um cartão para controlar compras,
                                    parcelas e faturas.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>
                                    Cadastrar primeiro cartão
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-3">
                        {cards.map((card) => (
                            <Card
                                key={card.id}
                                className={
                                    card.is_active ? undefined : 'opacity-70'
                                }
                            >
                                <CardContent className="p-0">
                                    <div className="flex flex-col lg:flex-row">
                                        <Link
                                            href={show(card.id)}
                                            className="group flex min-w-0 flex-1 flex-col gap-4 p-5 lg:flex-row lg:items-center"
                                        >
                                            <div className="flex min-w-0 flex-1 items-start gap-3">
                                                <div className="bg-muted rounded-full p-2.5">
                                                    <CreditCardIcon className="size-5" />
                                                </div>
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="truncate font-semibold">
                                                            {card.name}
                                                        </p>
                                                        <Badge
                                                            variant={
                                                                card.is_active
                                                                    ? 'secondary'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {card.is_active
                                                                ? 'Ativo'
                                                                : 'Inativo'}
                                                        </Badge>
                                                    </div>
                                                    <p className="text-muted-foreground mt-1 text-sm">
                                                        {card.institution ||
                                                            'Sem instituição'}{' '}
                                                        · final {card.last_four}
                                                        {card.holder_name
                                                            ? ' · ' +
                                                              card.holder_name
                                                            : ''}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="grid flex-1 grid-cols-2 gap-4 sm:grid-cols-4">
                                                <div>
                                                    <p className="text-muted-foreground text-xs uppercase">
                                                        Limite
                                                    </p>
                                                    <p className="font-medium tabular-nums">
                                                        {currency.format(
                                                            Number(
                                                                card.credit_limit,
                                                            ),
                                                        )}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground text-xs uppercase">
                                                        Usado
                                                    </p>
                                                    <p className="font-medium tabular-nums">
                                                        {currency.format(
                                                            Number(
                                                                card.used_limit,
                                                            ),
                                                        )}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground text-xs uppercase">
                                                        Disponível
                                                    </p>
                                                    <p className="font-medium tabular-nums">
                                                        {currency.format(
                                                            Number(
                                                                card.available_limit,
                                                            ),
                                                        )}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground text-xs uppercase">
                                                        Ciclo
                                                    </p>
                                                    <p className="font-medium">
                                                        Fecha {card.closing_day} ·
                                                        vence {card.due_day}
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
                                                <Link href={edit(card.id)}>
                                                    <Pencil />
                                                    Editar
                                                </Link>
                                            </Button>
                                            <Form
                                                {...CreditCardController.toggleStatus.form(
                                                    card.id,
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
                                                        {card.is_active
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

CreditCardsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Cartões',
            href: index(),
        },
    ],
};
