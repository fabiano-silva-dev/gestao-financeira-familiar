import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    CreditCard as CreditCardIcon,
    Pencil,
    Plus,
    Power,
} from 'lucide-react';
import CreditCardController from '@/actions/App/Http/Controllers/CreditCardController';
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
import { create, edit, index } from '@/routes/credit-cards';
import type { CreditCard } from '@/types';

type Props = {
    cards: CreditCard[];
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

export default function CreditCardsIndex() {
    const { cards, workspace } = usePage<Props>().props;

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
                            Cartões e ciclos de fatura de{' '}
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
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {cards.map((card) => (
                            <Card
                                key={card.id}
                                className={
                                    card.is_active ? undefined : 'opacity-70'
                                }
                            >
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <CardTitle className="truncate">
                                                {card.name}
                                            </CardTitle>
                                            <CardDescription>
                                                {card.institution ||
                                                    'Sem instituição'}{' '}
                                                · final {card.last_four}
                                            </CardDescription>
                                        </div>
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
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Limite
                                        </p>
                                        <p className="text-xl font-semibold tabular-nums">
                                            {currency.format(
                                                Number(card.credit_limit),
                                            )}
                                        </p>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Fecha
                                            </p>
                                            <p className="text-sm font-medium">
                                                Dia {card.closing_day}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">
                                                Vence
                                            </p>
                                            <p className="text-sm font-medium">
                                                Dia {card.due_day}
                                            </p>
                                        </div>
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Titular
                                        </p>
                                        <p className="text-sm font-medium">
                                            {card.holder_name ||
                                                'Não informado'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">
                                            Pagamento da fatura
                                        </p>
                                        <p className="text-sm font-medium">
                                            {card.invoice_payment_method_label}
                                            {card.payment_account_name
                                                ? ` · ${card.payment_account_name}`
                                                : ''}
                                        </p>
                                        {card.payment_instructions && (
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {card.payment_instructions}
                                            </p>
                                        )}
                                    </div>
                                </CardContent>
                                <CardFooter className="flex flex-wrap justify-end gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={edit(card.id)}>
                                            <Pencil />
                                            Editar
                                        </Link>
                                    </Button>
                                    <Form
                                        {...CreditCardController.toggleStatus.form(
                                            card.id,
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
                                                {card.is_active
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

CreditCardsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Cartões',
            href: index(),
        },
    ],
};
