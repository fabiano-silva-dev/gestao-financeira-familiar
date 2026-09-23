import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import FinancialRecurrenceController from '@/actions/App/Http/Controllers/FinancialRecurrenceController';
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
import { index } from '@/routes/recurrences';
import type {
    FinancialEntryReferenceOption,
    FinancialRecurrence,
    FinancialRecurrenceType,
    PaymentMethodOption,
    RecurrenceOption,
} from '@/types';

type Props = {
    recurrence?: FinancialRecurrence;
    defaultStartDate?: string;
    accountOptions: FinancialEntryReferenceOption[];
    cardOptions: FinancialEntryReferenceOption[];
    categoryOptions: FinancialEntryReferenceOption[];
    memberOptions: FinancialEntryReferenceOption[];
    paymentMethods: PaymentMethodOption[];
    frequencyOptions: RecurrenceOption[];
    typeOptions: RecurrenceOption[];
};

export default function FinancialRecurrenceForm({
    recurrence,
    defaultStartDate,
    accountOptions,
    cardOptions,
    categoryOptions,
    memberOptions,
    paymentMethods,
    frequencyOptions,
    typeOptions,
}: Props) {
    const [type, setType] = useState<FinancialRecurrenceType>(
        recurrence?.type ?? 'expense',
    );
    const [paymentMethod, setPaymentMethod] = useState(
        recurrence?.payment_method ?? 'pix',
    );
    const [accountSelection, setAccountSelection] = useState(
        recurrence?.financial_account_id
            ? String(recurrence.financial_account_id)
            : '',
    );
    const [cardSelection, setCardSelection] = useState(
        recurrence?.credit_card_id ? String(recurrence.credit_card_id) : '',
    );
    const [categorySelection, setCategorySelection] = useState(
        recurrence?.category_id ? String(recurrence.category_id) : 'none',
    );
    const [memberSelection, setMemberSelection] = useState(
        recurrence?.family_member_id
            ? String(recurrence.family_member_id)
            : 'none',
    );
    const [alreadySettled, setAlreadySettled] = useState(false);
    const [startsOn, setStartsOn] = useState(
        recurrence?.starts_on ?? defaultStartDate ?? '',
    );
    const [generationStartedOn, setGenerationStartedOn] = useState(
        recurrence?.generation_started_on ?? defaultStartDate ?? '',
    );

    const isExpense = type === 'expense';
    const usesCreditCard = isExpense && paymentMethod === 'credit_card';
    const filteredCategoryOptions = categoryOptions.filter(
        (category) => category.type === type,
    );
    const form = recurrence
        ? FinancialRecurrenceController.update.form(recurrence.id)
        : FinancialRecurrenceController.store.form();

    function changeStartsOn(value: string) {
        setStartsOn(value);

        if (
            generationStartedOn !== '' &&
            value !== '' &&
            generationStartedOn < value
        ) {
            setGenerationStartedOn(value);
        }
    }

    function changeType(value: string) {
        const nextType = value as FinancialRecurrenceType;
        setType(nextType);

        if (
            categorySelection !== 'none' &&
            !categoryOptions.some(
                (category) =>
                    String(category.id) === categorySelection &&
                    category.type === nextType,
            )
        ) {
            setCategorySelection('none');
        }

        if (nextType === 'income' && paymentMethod === 'credit_card') {
            setPaymentMethod('pix');
            setCardSelection('');
        }
    }

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={!recurrence}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="type">Tipo</Label>
                            <Select
                                name="type"
                                value={type}
                                onValueChange={changeType}
                                required
                            >
                                <SelectTrigger id="type" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {typeOptions.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.type} />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="description">Descrição</Label>
                            <Input
                                id="description"
                                name="description"
                                defaultValue={recurrence?.description}
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
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="amount">Valor</Label>
                            <Input
                                id="amount"
                                name="amount"
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0.01"
                                defaultValue={recurrence?.amount}
                                placeholder="0,00"
                                required
                            />
                            <InputError message={errors.amount} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="frequency">Frequência</Label>
                            <Select
                                name="frequency"
                                defaultValue={
                                    recurrence?.frequency ?? 'monthly'
                                }
                                required
                            >
                                <SelectTrigger
                                    id="frequency"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {frequencyOptions.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.frequency} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="interval">A cada</Label>
                            <Input
                                id="interval"
                                name="interval"
                                type="number"
                                inputMode="numeric"
                                min="1"
                                max="12"
                                defaultValue={recurrence?.interval ?? 1}
                                required
                            />
                            <p className="text-muted-foreground text-xs">
                                Ex.: 1 = todo período; 2 = a cada dois períodos.
                            </p>
                            <InputError message={errors.interval} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="starts_on">
                                Primeira ocorrência
                            </Label>
                            <Input
                                id="starts_on"
                                name="starts_on"
                                type="date"
                                value={startsOn}
                                onChange={(event) =>
                                    changeStartsOn(event.target.value)
                                }
                                required
                            />
                            <InputError message={errors.starts_on} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="generation_started_on">
                                Início da geração
                            </Label>
                            <Input
                                id="generation_started_on"
                                name="generation_started_on"
                                type="date"
                                min={startsOn || undefined}
                                value={generationStartedOn}
                                onChange={(event) =>
                                    setGenerationStartedOn(event.target.value)
                                }
                                required
                            />
                            <p className="text-muted-foreground text-xs">
                                {usesCreditCard
                                    ? 'Ocorrências já chegadas a partir desta data são lançadas no cartão ao salvar. As futuras continuam só como projeção.'
                                    : 'Ocorrências a partir desta data são criadas ao salvar. Uma data anterior a hoje gera o período retroativo como compromisso, sem alterar o saldo.'}
                            </p>
                            <InputError
                                message={errors.generation_started_on}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="ends_on">Encerrar em</Label>
                            <Input
                                id="ends_on"
                                name="ends_on"
                                type="date"
                                defaultValue={recurrence?.ends_on ?? ''}
                            />
                            <p className="text-muted-foreground text-xs">
                                Opcional. Sem data, a recorrência continua ativa
                                até ser pausada.
                            </p>
                            <InputError message={errors.ends_on} />
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
                                onValueChange={setPaymentMethod}
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
                                A cobrança só será lançada no cartão quando a
                                ocorrência chegar. Projeções futuras não criam
                                faturas antecipadamente.
                            </p>
                            <InputError message={errors.credit_card_id} />
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
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.family_member_id} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="payee_name">
                                {isExpense
                                    ? 'Favorecido ou beneficiário'
                                    : 'Pagador ou origem'}
                            </Label>
                            <Input
                                id="payee_name"
                                name="payee_name"
                                defaultValue={recurrence?.payee_name ?? ''}
                                placeholder="Ex.: Escola de esportes"
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
                                defaultValue={
                                    recurrence?.payment_instructions ?? ''
                                }
                                placeholder="Ex.: PIX para nome@provedor.com"
                                maxLength={500}
                            />
                            <p className="text-muted-foreground text-xs">
                                A instrução será copiada para cada compromisso
                                gerado.
                            </p>
                            <InputError message={errors.payment_instructions} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="notes">Observações</Label>
                        <Input
                            id="notes"
                            name="notes"
                            defaultValue={recurrence?.notes ?? ''}
                            placeholder="Informações opcionais"
                            maxLength={2000}
                        />
                        <InputError message={errors.notes} />
                    </div>

                    {!usesCreditCard && (
                        <AlreadySettledToggle
                            checked={alreadySettled}
                            isExpense={isExpense}
                            onCheckedChange={setAlreadySettled}
                            description={
                                alreadySettled
                                    ? `A ocorrência atual entra em transações recentes como ${isExpense ? 'Pago' : 'Recebido'} e altera o saldo. As próximas continuam como compromisso.`
                                    : 'As ocorrências não alteram o saldo nem o gráfico do período. Elas aparecem no fluxo de caixa, nos próximos vencimentos e nos atrasados.'
                            }
                        />
                    )}

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
                            {recurrence
                                ? 'Salvar alterações'
                                : 'Cadastrar recorrência'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
