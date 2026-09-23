import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
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

type CategoryOption = {
    id: number;
    label: string;
};

type PurchaseRow = {
    key: number;
    purchased_on: string;
    description: string;
    amount: string;
    installment_number: string;
    total_installments: string;
    category_id: string;
};

type Props = {
    cardOptions: CardOption[];
    categoryOptions: CategoryOption[];
    defaultReferenceMonth: string;
    defaultPurchaseDate: string;
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

function moneyToCents(value: string): number {
    const normalized = value.trim().replace(',', '.');

    if (!/^\d+(\.\d{0,2})?$/.test(normalized)) {
        return 0;
    }

    const [whole, decimal = ''] = normalized.split('.');

    return Number.parseInt(whole, 10) * 100
        + Number.parseInt(decimal.padEnd(2, '0').slice(0, 2), 10);
}

function newPurchase(key: number, purchasedOn: string): PurchaseRow {
    return {
        key,
        purchased_on: purchasedOn,
        description: '',
        amount: '',
        installment_number: '1',
        total_installments: '1',
        category_id: '',
    };
}

export default function CreditCardInvoicesCreate({
    cardOptions,
    categoryOptions,
    defaultReferenceMonth,
    defaultPurchaseDate,
}: Props) {
    const [cardSelection, setCardSelection] = useState(
        cardOptions[0] ? String(cardOptions[0].id) : '',
    );
    const [statementAmount, setStatementAmount] = useState('');
    const [nextKey, setNextKey] = useState(2);
    const [purchases, setPurchases] = useState<PurchaseRow[]>([
        newPurchase(1, defaultPurchaseDate),
    ]);
    const purchasesCents = useMemo(
        () =>
            purchases.reduce(
                (total, purchase) => total + moneyToCents(purchase.amount),
                0,
            ),
        [purchases],
    );
    const statementCents = moneyToCents(statementAmount);
    const differenceCents = statementCents - purchasesCents;

    const updatePurchase = (
        key: number,
        field: keyof Omit<PurchaseRow, 'key'>,
        value: string,
    ) => {
        setPurchases((current) =>
            current.map((purchase) =>
                purchase.key === key
                    ? { ...purchase, [field]: value }
                    : purchase,
            ),
        );
    };

    const addPurchase = () => {
        setPurchases((current) => [
            ...current,
            newPurchase(nextKey, defaultPurchaseDate),
        ]);
        setNextKey((current) => current + 1);
    };

    return (
        <>
            <Head title="Lançar fatura" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Lançar fatura manualmente
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Informe o total da fatura e, quando desejar, as compras.
                        Cada compra passa pelo mesmo motor de conciliação usado
                        na importação.
                    </p>
                </div>

                <Form
                    {...CreditCardInvoiceController.store.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Dados da fatura</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-6">
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
                                            value={statementAmount}
                                            onChange={(event) =>
                                                setStatementAmount(
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                        <InputError
                                            message={errors.statement_amount}
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between gap-4">
                                    <div>
                                        <CardTitle>Compras da fatura</CardTitle>
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            Categoria é opcional: regras
                                            existentes podem classificar e
                                            conciliar automaticamente.
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addPurchase}
                                    >
                                        <Plus />
                                        Adicionar compra
                                    </Button>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {purchases.length === 0 ? (
                                        <div className="rounded-lg border border-dashed p-4 text-sm">
                                            <p className="font-medium">
                                                Nenhuma compra informada
                                            </p>
                                            <p className="text-muted-foreground mt-1">
                                                Você pode salvar somente o
                                                total da fatura ou adicionar as
                                                compras para processamento e
                                                conciliação.
                                            </p>
                                        </div>
                                    ) : (
                                        purchases.map((purchase, index) => (
                                            <div
                                                key={purchase.key}
                                                className="rounded-lg border p-4"
                                            >
                                                <div className="mb-4 flex items-center justify-between">
                                                    <p className="text-sm font-medium">
                                                        Compra {index + 1}
                                                    </p>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={
                                                            'Remover compra ' +
                                                            (index + 1)
                                                        }
                                                        onClick={() =>
                                                            setPurchases(
                                                                (current) =>
                                                                    current.filter(
                                                                        (row) =>
                                                                            row.key !==
                                                                            purchase.key,
                                                                    ),
                                                            )
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </div>

                                                <div className="grid gap-4 lg:grid-cols-12">
                                                    <div className="grid gap-2 lg:col-span-2">
                                                        <Label>
                                                            Data
                                                        </Label>
                                                        <Input
                                                            name={
                                                                'purchases[' +
                                                                index +
                                                                '][purchased_on]'
                                                            }
                                                            type="date"
                                                            value={
                                                                purchase.purchased_on
                                                            }
                                                            onChange={(event) =>
                                                                updatePurchase(
                                                                    purchase.key,
                                                                    'purchased_on',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            required
                                                        />
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    'purchases.' +
                                                                        index +
                                                                        '.purchased_on'
                                                                ]
                                                            }
                                                        />
                                                    </div>

                                                    <div className="grid gap-2 lg:col-span-4">
                                                        <Label>
                                                            Descrição
                                                        </Label>
                                                        <Input
                                                            name={
                                                                'purchases[' +
                                                                index +
                                                                '][description]'
                                                            }
                                                            value={
                                                                purchase.description
                                                            }
                                                            onChange={(event) =>
                                                                updatePurchase(
                                                                    purchase.key,
                                                                    'description',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder="Ex.: Mercado, posto, farmácia"
                                                            maxLength={255}
                                                            required
                                                        />
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    'purchases.' +
                                                                        index +
                                                                        '.description'
                                                                ]
                                                            }
                                                        />
                                                    </div>

                                                    <div className="grid gap-2 lg:col-span-2">
                                                        <Label>
                                                            Valor
                                                        </Label>
                                                        <Input
                                                            name={
                                                                'purchases[' +
                                                                index +
                                                                '][amount]'
                                                            }
                                                            type="number"
                                                            inputMode="decimal"
                                                            step="0.01"
                                                            min="0.01"
                                                            value={
                                                                purchase.amount
                                                            }
                                                            onChange={(event) =>
                                                                updatePurchase(
                                                                    purchase.key,
                                                                    'amount',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            required
                                                        />
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    'purchases.' +
                                                                        index +
                                                                        '.amount'
                                                                ]
                                                            }
                                                        />
                                                    </div>

                                                    <div className="grid gap-2 lg:col-span-2">
                                                        <Label>
                                                            Parcela atual
                                                        </Label>
                                                        <Input
                                                            name={
                                                                'purchases[' +
                                                                index +
                                                                '][installment_number]'
                                                            }
                                                            type="number"
                                                            min="1"
                                                            max="999"
                                                            value={
                                                                purchase.installment_number
                                                            }
                                                            onChange={(event) =>
                                                                updatePurchase(
                                                                    purchase.key,
                                                                    'installment_number',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            required
                                                        />
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    'purchases.' +
                                                                        index +
                                                                        '.installment_number'
                                                                ]
                                                            }
                                                        />
                                                    </div>

                                                    <div className="grid gap-2 lg:col-span-2">
                                                        <Label>
                                                            Total parcelas
                                                        </Label>
                                                        <Input
                                                            name={
                                                                'purchases[' +
                                                                index +
                                                                '][total_installments]'
                                                            }
                                                            type="number"
                                                            min="1"
                                                            max="999"
                                                            value={
                                                                purchase.total_installments
                                                            }
                                                            onChange={(event) =>
                                                                updatePurchase(
                                                                    purchase.key,
                                                                    'total_installments',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            required
                                                        />
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    'purchases.' +
                                                                        index +
                                                                        '.total_installments'
                                                                ]
                                                            }
                                                        />
                                                    </div>

                                                    <div className="grid gap-2 lg:col-span-6">
                                                        <Label>
                                                            Categoria
                                                        </Label>
                                                        <input
                                                            type="hidden"
                                                            name={
                                                                'purchases[' +
                                                                index +
                                                                '][category_id]'
                                                            }
                                                            value={
                                                                purchase.category_id
                                                            }
                                                        />
                                                        <Select
                                                            value={
                                                                purchase.category_id ===
                                                                ''
                                                                    ? 'automatic'
                                                                    : purchase.category_id
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                updatePurchase(
                                                                    purchase.key,
                                                                    'category_id',
                                                                    value ===
                                                                        'automatic'
                                                                        ? ''
                                                                        : value,
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger className="w-full">
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="automatic">
                                                                    Automática /
                                                                    pendente
                                                                </SelectItem>
                                                                {categoryOptions.map(
                                                                    (
                                                                        category,
                                                                    ) => (
                                                                        <SelectItem
                                                                            key={
                                                                                category.id
                                                                            }
                                                                            value={String(
                                                                                category.id,
                                                                            )}
                                                                        >
                                                                            {
                                                                                category.label
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    'purchases.' +
                                                                        index +
                                                                        '.category_id'
                                                                ]
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        ))
                                    )}

                                    <div className="bg-muted/40 grid gap-2 rounded-lg p-4 text-sm sm:grid-cols-3">
                                        <div>
                                            <p className="text-muted-foreground">
                                                Total da fatura
                                            </p>
                                            <p className="font-semibold tabular-nums">
                                                {currency.format(
                                                    statementCents / 100,
                                                )}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">
                                                Compras informadas
                                            </p>
                                            <p className="font-semibold tabular-nums">
                                                {currency.format(
                                                    purchasesCents / 100,
                                                )}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">
                                                Diferença
                                            </p>
                                            <p className="font-semibold tabular-nums">
                                                {currency.format(
                                                    differenceCents / 100,
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            <p className="text-muted-foreground text-sm">
                                Ao salvar, cada compra é normalizada como linha
                                de fatura. O sistema tenta conciliar com compras
                                existentes, aplica regras de categoria e cria a
                                despesa e as parcelas somente quando necessário.
                                O pagamento da fatura continua sendo apenas
                                movimento de caixa.
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
