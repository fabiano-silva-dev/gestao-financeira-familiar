import { Form, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import FinancialTransactionController from '@/actions/App/Http/Controllers/FinancialTransactionController';
import { AlreadySettledToggle } from '@/components/finance/already-settled-toggle';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
    defaultAccountId?: string | null;
    returnAccountId?: string | null;
    returnPeriod?: string | null;
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
    defaultAccountId,
    returnAccountId,
    returnPeriod,
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
    const [confirmRevertOpen, setConfirmRevertOpen] = useState(false);
    const [revertingSettlement, setRevertingSettlement] = useState(false);
    const [revertError, setRevertError] = useState<string | null>(null);
    const [status, setStatus] = useState(entry?.status ?? 'confirmed');
    const [settlementDate, setSettlementDate] = useState(
        entry?.settled_on ?? (!entry ? (defaultDate ?? '') : ''),
    );
    const [dueDate, setDueDate] = useState(entry?.due_date ?? '');
    const [accountSelection, setAccountSelection] = useState(
        entry?.financial_account_id
            ? String(entry.financial_account_id)
            : entry?.source_account_id
              ? String(entry.source_account_id)
              : (defaultAccountId ?? ''),
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
    const [installmentMode, setInstallmentMode] = useState<
        'single' | 'installments'
    >(
        entry?.credit_card_id == null && (entry?.installment_count ?? 1) > 1
            ? 'installments'
            : 'single',
    );
    const [installmentCount, setInstallmentCount] = useState(
        Math.max(1, entry?.installment_count ?? 1),
    );
    const cashInstallmentPlan =
        isExpense && !usesCreditCard && installmentMode === 'installments';
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
        setInstallmentMode('single');
        setInstallmentCount(1);
    }, [entryType, categoryOptions, paymentMethods]);

    function applyAlreadySettled(checked: boolean) {
        setAlreadySettled(checked);

        if (isCancelled) {
            return;
        }

        setStatus(checked ? 'confirmed' : 'planned');
        setSettlementDate(checked ? settlementDate || defaultDate || '' : '');
    }

    function changeAlreadySettled(checked: boolean) {
        if (
            !checked &&
            entry?.financial_recurrence_id != null &&
            entry.is_settled &&
            !isCancelled
        ) {
            setRevertError(null);
            setConfirmRevertOpen(true);

            return;
        }

        applyAlreadySettled(checked);
    }

    function confirmRevertSettlement() {
        if (entry == null) {
            return;
        }

        setRevertingSettlement(true);
        setRevertError(null);
        router.patch(
            FinancialTransactionController.revertRecurrenceSettlement.url(
                entry.id,
            ),
            {},
            {
                preserveScroll: true,
                onError: (errors) => {
                    setRevertError(
                        errors.settlement ??
                            'Não foi possível excluir o lançamento.',
                    );
                },
                onSuccess: () => setConfirmRevertOpen(false),
                onFinish: () => setRevertingSettlement(false),
            },
        );
    }
    const form = entry
        ? FinancialTransactionController.update.form(entry.id)
        : FinancialTransactionController.store.form();
    const returnHref =
        !entry && returnAccountId
            ? `/contas/${returnAccountId}${
                  returnPeriod
                      ? `?period=${encodeURIComponent(returnPeriod)}`
                      : ''
              }`
            : index();

    function changeInstallmentMode(value: 'single' | 'installments') {
        setInstallmentMode(value);

        if (value === 'installments') {
            setInstallmentCount((current) => Math.max(2, current));
            setDueDate((current) => current || defaultDate || '');

            return;
        }

        setInstallmentCount(1);
    }

    function changePaymentMethod(value: string) {
        setPaymentMethod(value);

        if (isExpense && value === 'credit_card') {
            setStatus(
                entry?.status === 'cancelled' ? 'cancelled' : 'confirmed',
            );
            setAlreadySettled(false);
            setSettlementDate('');
            setInstallmentMode('single');

            if (entry?.credit_card_id == null) {
                setInstallmentCount(1);
            }

            return;
        }

        if (installmentMode === 'installments') {
            setDueDate((current) => current || defaultDate || '');
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
                    {!entry && returnAccountId && (
                        <input
                            type="hidden"
                            name="_return_account"
                            value={returnAccountId}
                        />
                    )}
                    {!entry && returnPeriod && (
                        <input
                            type="hidden"
                            name="_return_period"
                            value={returnPeriod}
                        />
                    )}

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
                                    value={dueDate}
                                    onChange={(event) =>
                                        setDueDate(event.target.value)
                                    }
                                    required={
                                        cashInstallmentPlan ||
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

                    {isExpense && !usesCreditCard && (
                        <div className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="installment_mode">
                                    Forma do compromisso
                                </Label>
                                <Select
                                    value={installmentMode}
                                    onValueChange={(value) =>
                                        changeInstallmentMode(
                                            value as
                                                | 'single'
                                                | 'installments',
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="installment_mode"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="single">
                                            Pagamento único
                                        </SelectItem>
                                        <SelectItem value="installments">
                                            Parcelado, sem recorrência
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="text-muted-foreground text-xs">
                                    Use parcelado para compromissos com fim
                                    definido. Isso não cria uma recorrência.
                                </p>
                            </div>

                            {cashInstallmentPlan ? (
                                <div className="grid gap-2">
                                    <Label htmlFor="installment_count">
                                        Quantidade de parcelas
                                    </Label>
                                    <Input
                                        id="installment_count"
                                        name="installment_count"
                                        type="number"
                                        min="2"
                                        max="60"
                                        step="1"
                                        value={installmentCount}
                                        onChange={(event) =>
                                            setInstallmentCount(
                                                Math.max(
                                                    2,
                                                    Number(
                                                        event.target.value,
                                                    ) || 2,
                                                ),
                                            )
                                        }
                                        required
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        O valor total será dividido entre as
                                        parcelas. As próximas vencem
                                        mensalmente no mesmo dia.
                                    </p>
                                    <InputError
                                        message={errors.installment_count}
                                    />
                                </div>
                            ) : (
                                <input
                                    type="hidden"
                                    name="installment_count"
                                    value="1"
                                />
                            )}
                        </div>
                    )}

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
                                    value={installmentCount}
                                    onChange={(event) =>
                                        setInstallmentCount(
                                            Math.max(
                                                1,
                                                Number(event.target.value) || 1,
                                            ),
                                        )
                                    }
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
                                label={
                                    cashInstallmentPlan
                                        ? 'Primeira parcela já paga'
                                        : undefined
                                }
                                onCheckedChange={changeAlreadySettled}
                                description={
                                    cashInstallmentPlan
                                        ? alreadySettled && !isCancelled
                                            ? 'Somente a primeira parcela altera o saldo agora. As demais ficam em aberto nos meses seguintes.'
                                            : 'Todas as parcelas ficam em aberto e entram na projeção dos próximos meses.'
                                        : alreadySettled && !isCancelled
                                          ? `Aparece em transações recentes como ${isExpense ? 'Pago' : 'Recebido'} e altera o saldo da conta.`
                                          : 'Não altera o saldo nem o gráfico do período. Serve para o fluxo de caixa, próximos vencimentos e atrasados.'
                                }
                            />

                            {canSettle ? (
                                <div className="grid gap-2">
                                    <Label htmlFor="settled_on">
                                        {cashInstallmentPlan
                                            ? 'Data efetiva da 1ª parcela'
                                            : isExpense
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
                            <Link href={returnHref}>Cancelar</Link>
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

                    <Dialog
                        open={confirmRevertOpen}
                        onOpenChange={(open) => {
                            if (!revertingSettlement) {
                                setConfirmRevertOpen(open);
                            }
                        }}
                    >
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Excluir o lançamento?</DialogTitle>
                                <DialogDescription>
                                    Isso exclui o lançamento{' '}
                                    {isExpense ? 'pago' : 'recebido'} e grava a
                                    ocorrência da recorrência como pendente.
                                    {entry?.origin_source.filename != null
                                        ? ' A conciliação bancária também será desfeita.'
                                        : ''}
                                </DialogDescription>
                            </DialogHeader>
                            <InputError message={revertError ?? undefined} />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={revertingSettlement}
                                    onClick={() => setConfirmRevertOpen(false)}
                                >
                                    Cancelar
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    disabled={revertingSettlement}
                                    data-test="confirm-revert-recurrence-settlement"
                                    onClick={confirmRevertSettlement}
                                >
                                    Excluir e salvar
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </>
            )}
        </Form>
    );
}
