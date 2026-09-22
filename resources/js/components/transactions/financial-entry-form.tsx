import { Form, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import FinancialTransactionController from '@/actions/App/Http/Controllers/FinancialTransactionController';
import { AlreadySettledToggle } from '@/components/finance/already-settled-toggle';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/transactions';
import type {
    FinancialEntry,
    FinancialEntryReferenceOption,
    FinancialEntryType,
    PaymentMethodOption,
} from '@/types';

type Props = {
    entry?: FinancialEntry;
    entryType?: FinancialEntryType;
    defaultDate?: string;
    accountOptions: FinancialEntryReferenceOption[];
    cardOptions: FinancialEntryReferenceOption[];
    categoryOptions: FinancialEntryReferenceOption[];
    memberOptions: FinancialEntryReferenceOption[];
    paymentMethods: PaymentMethodOption[];
};

export default function FinancialEntryForm({
    entry,
    entryType: newEntryType,
    defaultDate,
    accountOptions,
    cardOptions,
    categoryOptions,
    memberOptions,
    paymentMethods,
}: Props) {
    const entryType = newEntryType ?? entry?.type ?? 'expense';
    const isExpense = entryType === 'expense';
    const filteredCategoryOptions = categoryOptions.filter(
        (category) => category.type == null || category.type === entryType,
    );
    const [paymentMethod, setPaymentMethod] = useState(() => {
        const method =
            entry?.payment_method ?? paymentMethods[0]?.value ?? 'pix';

        if (!isExpense && method === 'credit_card') {
            return (
                paymentMethods.find((item) => item.value !== 'credit_card')
                    ?.value ?? 'pix'
            );
        }

        return method;
    });
    const [alreadySettled, setAlreadySettled] = useState(
        entry ? entry.is_settled : true,
    );
    const [status, setStatus] = useState(entry?.status ?? 'confirmed');
    const [settlementDate, setSettlementDate] = useState(
        entry?.settled_on ?? (!entry ? (defaultDate ?? '') : ''),
    );
    const [accountSelection, setAccountSelection] = useState(
        entry?.financial_account_id
            ? String(entry.financial_account_id)
            : entry?.source_account_id
              ? String(entry.source_account_id)
              : '',
    );
    const [cardSelection, setCardSelection] = useState(
        entry?.credit_card_id ? String(entry.credit_card_id) : '',
    );
    const [categorySelection, setCategorySelection] = useState(() => {
        if (entry?.category_id == null) {
            return 'none';
        }

        const selected = categoryOptions.find(
            (category) => category.id === entry.category_id,
        );

        if (selected?.type != null && selected.type !== entryType) {
            return 'none';
        }

        return String(entry.category_id);
    });
    const [memberSelection, setMemberSelection] = useState(
        entry?.family_member_id ? String(entry.family_member_id) : 'none',
    );
    const usesCreditCard = isExpense && paymentMethod === 'credit_card';
    const isCancelled = status === 'cancelled';
    const canSettle = !usesCreditCard && alreadySettled && !isCancelled;
    const entryStatus = isCancelled
        ? 'cancelled'
        : alreadySettled || usesCreditCard
          ? 'confirmed'
          : 'planned';

    useEffect(() => {
        setCategorySelection((current) => {
            if (current === 'none') {
                return current;
            }

            const selected = categoryOptions.find(
                (category) => String(category.id) === current,
            );

            if (selected?.type != null && selected.type !== entryType) {
                return 'none';
            }

            return current;
        });

        if (entryType === 'expense') {
            return;
        }

        setPaymentMethod((current) =>
            current === 'credit_card'
                ? (paymentMethods.find((item) => item.value !== 'credit_card')
                      ?.value ?? 'pix')
                : current,
        );
        setCardSelection('');
    }, [entryType, categoryOptions, paymentMethods]);

    function changeAlreadySettled(checked: boolean) {
        setAlreadySettled(checked);

        if (isCancelled) {
            return;
        }

        setStatus(checked ? 'confirmed' : 'planned');
        setSettlementDate(checked ? settlementDate || defaultDate || '' : '');
    }
    const form = entry
        ? FinancialTransactionController.update.form(entry.id)
        : FinancialTransactionController.store.form();

    function changePaymentMethod(value: string) {
        setPaymentMethod(value);

        if (isExpense && value === 'credit_card') {
            setStatus(
                entry?.status === 'cancelled' ? 'cancelled' : 'confirmed',
            );
            setAlreadySettled(false);
            setSettlementDate('');
        }
    }

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={!entry}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="type" value={entryType} />

                    <div className="grid gap-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <div className="grid gap-2">
                            <Label htmlFor="description">Descrição</Label>
                            <Input
                                id="description"
                                name="description"
                                defaultValue={entry?.description}
                                placeholder={
                                    isExpense
                                        ? 'Ex.: Vôlei e handebol'
                                        : 'Ex.: Salário mensal'
                                }
                                maxLength={160}
                                required
                                autoFocus
                            />
                            <InputError message={errors.description} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="amount">Valor total</Label>
                            <Input
                                id="amount"
                                name="amount"
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0.01"
                                defaultValue={entry?.amount}
                                placeholder="0,00"
                                required
                            />
                            <InputError message={errors.amount} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="transaction_date">
                                Data do fato
                            </Label>
                            <Input
                                id="transaction_date"
                                name="transaction_date"
                                type="date"
                                defaultValue={
                                    entry?.transaction_date ?? defaultDate
                                }
                                required
                            />
                            <InputError message={errors.transaction_date} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="competence_date">Competência</Label>
                            <Input
                                id="competence_date"
                                name="competence_date"
                                type="date"
                                defaultValue={
                                    entry?.competence_date ??
                                    entry?.transaction_date ??
                                    defaultDate
                                }
                                required
                            />
                            <p className="text-muted-foreground text-xs">
                                Define o mês da análise gerencial.
                            </p>
                            <InputError message={errors.competence_date} />
                        </div>

                        {usesCreditCard ? (
                            <div className="bg-muted/50 rounded-lg p-3 text-sm">
                                <input type="hidden" name="due_date" value="" />
                                <input
                                    type="hidden"
                                    name="settled_on"
                                    value=""
                                />
                                <p className="font-medium">
                                    Vencimento definido pela fatura
                                </p>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    A compra entra no ciclo do cartão conforme a
                                    data do fato financeiro e o fechamento.
                                </p>
                                <InputError message={errors.due_date} />
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="due_date">Vencimento</Label>
                                <Input
                                    id="due_date"
                                    name="due_date"
                                    type="date"
                                    defaultValue={entry?.due_date ?? ''}
                                    required={
                                        entryStatus === 'planned' ||
                                        (entryStatus === 'confirmed' &&
                                            !settlementDate)
                                    }
                                />
                                <InputError message={errors.due_date} />
                            </div>
                        )}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="category_id">Categoria</Label>
                            <input
                                type="hidden"
                                name="category_id"
                                value={
                                    categorySelection === 'none'
                                        ? ''
                                        : categorySelection
                                }
                            />
                            <Select
                                value={categorySelection}
                                onValueChange={setCategorySelection}
                            >
                                <SelectTrigger
                                    id="category_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Sem categoria
                                    </SelectItem>
                                    {filteredCategoryOptions.map((category) => (
                                        <SelectItem
                                            key={category.id}
                                            value={String(category.id)}
                                        >
                                            {category.label ?? category.name}
                                            {category.is_active
                                                ? ''
                                                : ' (inativa)'}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.category_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="family_member_id">Pessoa</Label>
                            <input
                                type="hidden"
                                name="family_member_id"
                                value={
                                    memberSelection === 'none'
                                        ? ''
                                        : memberSelection
                                }
                            />
                            <Select
                                value={memberSelection}
                                onValueChange={setMemberSelection}
                            >
                                <SelectTrigger
                                    id="family_member_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Não informada
                                    </SelectItem>
                                    {memberOptions.map((member) => (
                                        <SelectItem
                                            key={member.id}
                                            value={String(member.id)}
                                        >
                                            {member.name}
                                            {member.is_active
                                                ? ''
                                                : ' (inativa)'}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.family_member_id} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="payment_method">
                            {isExpense
                                ? 'Forma de pagamento'
                                : 'Forma de recebimento'}
                        </Label>
                        <Select
                            name="payment_method"
                            value={paymentMethod}
                            onValueChange={changePaymentMethod}
                            required
                        >
                            <SelectTrigger
                                id="payment_method"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {paymentMethods
                                    .filter(
                                        (method) =>
                                            isExpense ||
                                            method.value !== 'credit_card',
                                    )
                                    .map((method) => (
                                        <SelectItem
                                            key={method.value}
                                            value={method.value}
                                        >
                                            {method.label}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.payment_method} />
                    </div>

                    {usesCreditCard ? (
                        <div className="grid gap-2">
                            <input
                                type="hidden"
                                name="financial_account_id"
                                value=""
                            />
                            <Label htmlFor="credit_card_id">Cartão</Label>
                            <Select
                                name="credit_card_id"
                                value={cardSelection}
                                onValueChange={setCardSelection}
                                required
                            >
                                <SelectTrigger
                                    id="credit_card_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Selecione o cartão" />
                                </SelectTrigger>
                                <SelectContent>
                                    {cardOptions.map((card) => (
                                        <SelectItem
                                            key={card.id}
                                            value={String(card.id)}
                                        >
                                            {card.label ?? card.name}
                                            {card.is_active ? '' : ' (inativo)'}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                O gasto é reconhecido na compra. O saldo
                                bancário só muda quando a fatura for paga.
                            </p>
                            <InputError message={errors.credit_card_id} />

                            <div className="mt-2 grid gap-2">
                                <Label htmlFor="installment_count">
                                    Quantidade de parcelas
                                </Label>
                                <Input
                                    id="installment_count"
                                    name="installment_count"
                                    type="number"
                                    min="1"
                                    max="60"
                                    step="1"
                                    defaultValue={entry?.installment_count ?? 1}
                                    required
                                />
                                <p className="text-muted-foreground text-xs">
                                    O valor total da compra será preservado e
                                    distribuído entre as parcelas vinculadas.
                                </p>
                                <InputError
                                    message={errors.installment_count}
                                />
                            </div>
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <input
                                type="hidden"
                                name="credit_card_id"
                                value=""
                            />
                            <Label htmlFor="financial_account_id">
                                {isExpense
                                    ? 'Conta de pagamento'
                                    : 'Conta de recebimento'}
                            </Label>
                            <Select
                                name="financial_account_id"
                                value={accountSelection}
                                onValueChange={setAccountSelection}
                                required
                            >
                                <SelectTrigger
                                    id="financial_account_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Selecione a conta" />
                                </SelectTrigger>
                                <SelectContent>
                                    {accountOptions.map((account) => (
                                        <SelectItem
                                            key={account.id}
                                            value={String(account.id)}
                                        >
                                            {account.name}
                                            {account.is_active
                                                ? ''
                                                : ' (inativa)'}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.financial_account_id} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="payee_name">
                            {isExpense
                                ? 'Favorecido ou beneficiário'
                                : 'Pagador ou origem'}
                        </Label>
                        <Input
                            id="payee_name"
                            name="payee_name"
                            defaultValue={entry?.payee_name ?? ''}
                            placeholder={
                                isExpense
                                    ? 'Ex.: Escola de esportes'
                                    : 'Ex.: Empresa pagadora'
                            }
                            maxLength={160}
                        />
                        <InputError message={errors.payee_name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="payment_instructions">
                            Instruções de pagamento
                        </Label>
                        <Input
                            id="payment_instructions"
                            name="payment_instructions"
                            defaultValue={entry?.payment_instructions ?? ''}
                            placeholder="Ex.: PIX para nome@provedor.com"
                            maxLength={500}
                        />
                        <p className="text-muted-foreground text-xs">
                            Informe chave PIX, referência do boleto ou outra
                            orientação necessária para o vencimento.
                        </p>
                        <InputError message={errors.payment_instructions} />
                    </div>

                    {usesCreditCard ? (
                        <div className="bg-muted/50 rounded-lg p-3 text-sm">
                            <input
                                type="hidden"
                                name="status"
                                value={
                                    entry?.status === 'cancelled'
                                        ? 'cancelled'
                                        : 'confirmed'
                                }
                            />
                            <input type="hidden" name="settled_on" value="" />
                            <p className="font-medium">Compra confirmada</p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Compras no cartão geram parcelas e faturas, sem
                                saída imediata da conta bancária.
                            </p>
                            <InputError message={errors.status} />
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <input
                                type="hidden"
                                name="status"
                                value={entryStatus}
                            />
                            <AlreadySettledToggle
                                checked={alreadySettled && !isCancelled}
                                isExpense={isExpense}
                                disabled={isCancelled}
                                name="entry_already_settled"
                                onCheckedChange={changeAlreadySettled}
                                description={
                                    alreadySettled && !isCancelled
                                        ? `Aparece em transações recentes como ${isExpense ? 'Pago' : 'Recebido'} e altera o saldo da conta.`
                                        : 'Não altera o saldo nem o gráfico do período. Serve para o fluxo de caixa, próximos vencimentos e atrasados.'
                                }
                            />

                            {canSettle ? (
                                <div className="grid gap-2">
                                    <Label htmlFor="settled_on">
                                        {isExpense
                                            ? 'Data efetiva do pagamento'
                                            : 'Data efetiva do recebimento'}
                                    </Label>
                                    <Input
                                        id="settled_on"
                                        name="settled_on"
                                        type="date"
                                        value={settlementDate}
                                        onChange={(event) =>
                                            setSettlementDate(
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <InputError message={errors.settled_on} />
                                </div>
                            ) : (
                                <input
                                    type="hidden"
                                    name="settled_on"
                                    value=""
                                />
                            )}
                            <InputError message={errors.status} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="notes">Observações</Label>
                        <Input
                            id="notes"
                            name="notes"
                            defaultValue={entry?.notes ?? ''}
                            placeholder="Informações opcionais"
                            maxLength={2000}
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button
                            variant="outline"
                            className="w-full sm:w-auto"
                            asChild
                        >
                            <Link href={index()}>Cancelar</Link>
                        </Button>
                        <Button
                            className="w-full sm:w-auto"
                            disabled={processing}
                        >
                            {entry
                                ? 'Salvar alterações'
                                : `Cadastrar ${isExpense ? 'despesa' : 'receita'}`}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
