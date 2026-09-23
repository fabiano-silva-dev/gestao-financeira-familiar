import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import CreditCardInvoiceController from '@/actions/App/Http/Controllers/CreditCardInvoiceController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { create, index } from '@/routes/credit-card-invoices';

type CardOption = {
    id: number;
    label: string;
};

type Props = {
    cardOptions: CardOption[];
    defaultReferenceMonth: string;
};

export default function CreditCardInvoicesCreate({
    cardOptions,
    defaultReferenceMonth,
}: Props) {
    const [cardSelection, setCardSelection] = useState(
        cardOptions[0] ? String(cardOptions[0].id) : '',
    );

    return (
        <>
            <Head title="Lançar fatura" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Lançar fatura manualmente
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Registre a obrigação da fatura sem criar uma nova
                        despesa ou movimentação de caixa.
                    </p>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Dados da fatura</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...CreditCardInvoiceController.store.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="credit_card_id">
                                            Cartão
                                        </Label>
                                        <input
                                            type="hidden"
                                            name="credit_card_id"
                                            value={cardSelection}
                                        />
                                        <Select
                                            value={cardSelection}
                                            onValueChange={setCardSelection}
                                            disabled={cardOptions.length === 0}
                                        >
                                            <SelectTrigger
                                                id="credit_card_id"
                                                className="w-full"
                                            >
                                                <SelectValue
                                                    placeholder={
                                                        cardOptions.length === 0
                                                            ? 'Nenhum cartão cadastrado'
                                                            : 'Selecione o cartão'
                                                    }
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {cardOptions.map((card) => (
                                                    <SelectItem
                                                        key={card.id}
                                                        value={String(card.id)}
                                                    >
                                                        {card.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.credit_card_id}
                                        />
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="reference_month">
                                                Mês de referência
                                            </Label>
                                            <Input
                                                id="reference_month"
                                                name="reference_month"
                                                type="month"
                                                defaultValue={
                                                    defaultReferenceMonth
                                                }
                                                required
                                            />
                                            <InputError
                                                message={
                                                    errors.reference_month
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="due_date">
                                                Vencimento
                                            </Label>
                                            <Input
                                                id="due_date"
                                                name="due_date"
                                                type="date"
                                                required
                                            />
                                            <InputError
                                                message={errors.due_date}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="statement_amount">
                                            Valor total da fatura
                                        </Label>
                                        <Input
                                            id="statement_amount"
                                            name="statement_amount"
                                            type="number"
                                            inputMode="decimal"
                                            step="0.01"
                                            min="0.01"
                                            placeholder="0,00"
                                            required
                                        />
                                        <InputError
                                            message={errors.statement_amount}
                                        />
                                    </div>

                                    <p className="text-muted-foreground text-sm">
                                        O valor informado representa a obrigação
                                        da fatura. As compras continuam sendo
                                        despesas separadas e o pagamento da
                                        fatura apenas movimenta o caixa.
                                    </p>

                                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                        <Button
                                            variant="outline"
                                            asChild
                                            className="sm:min-w-28"
                                        >
                                            <Link href={index()}>Cancelar</Link>
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                cardOptions.length === 0
                                            }
                                            className="sm:min-w-36"
                                        >
                                            Salvar fatura
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CreditCardInvoicesCreate.layout = {
    breadcrumbs: [
        { title: 'Faturas', href: index() },
        { title: 'Lançar fatura', href: create() },
    ],
};
