import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import CreditCardController from '@/actions/App/Http/Controllers/CreditCardController';
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
import { index } from '@/routes/credit-cards';
import type {
    CreditCard,
    CreditCardReferenceOption,
    PaymentMethodOption,
} from '@/types';

type Props = {
    card?: CreditCard;
    memberOptions: CreditCardReferenceOption[];
    accountOptions: CreditCardReferenceOption[];
    invoicePaymentMethods: PaymentMethodOption[];
};

export default function CreditCardForm({
    card,
    memberOptions,
    accountOptions,
    invoicePaymentMethods,
}: Props) {
    const [holderSelection, setHolderSelection] = useState(
        card?.holder_id ? String(card.holder_id) : 'none',
    );
    const [accountSelection, setAccountSelection] = useState(
        card?.payment_account_id ? String(card.payment_account_id) : 'none',
    );
    const form = card
        ? CreditCardController.update.form(card.id)
        : CreditCardController.store.form();

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={!card}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nome do cartão</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={card?.name}
                                placeholder="Ex.: Tumelero"
                                maxLength={120}
                                required
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="institution">Instituição</Label>
                            <Input
                                id="institution"
                                name="institution"
                                defaultValue={card?.institution ?? ''}
                                placeholder="Ex.: Nubank ou Sicredi"
                                maxLength={120}
                            />
                            <InputError message={errors.institution} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="last_four">Final do cartão</Label>
                            <Input
                                id="last_four"
                                name="last_four"
                                defaultValue={card?.last_four}
                                placeholder="1234"
                                inputMode="numeric"
                                pattern="[0-9]{4}"
                                maxLength={4}
                                required
                            />
                            <InputError message={errors.last_four} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="credit_limit">Limite</Label>
                            <Input
                                id="credit_limit"
                                name="credit_limit"
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0"
                                defaultValue={card?.credit_limit}
                                placeholder="0,00"
                                required
                            />
                            <InputError message={errors.credit_limit} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="holder_id">Titular</Label>
                        <input
                            type="hidden"
                            name="holder_id"
                            value={
                                holderSelection === 'none'
                                    ? ''
                                    : holderSelection
                            }
                        />
                        <Select
                            value={holderSelection}
                            onValueChange={setHolderSelection}
                        >
                            <SelectTrigger id="holder_id" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Não informado
                                </SelectItem>
                                {memberOptions.map((member) => (
                                    <SelectItem
                                        key={member.id}
                                        value={String(member.id)}
                                    >
                                        {member.name}
                                        {member.is_active ? '' : ' (inativa)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.holder_id} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="closing_day">
                                Dia de fechamento
                            </Label>
                            <Input
                                id="closing_day"
                                name="closing_day"
                                type="number"
                                min="1"
                                max="31"
                                defaultValue={card?.closing_day}
                                required
                            />
                            <InputError message={errors.closing_day} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="due_day">Dia de vencimento</Label>
                            <Input
                                id="due_day"
                                name="due_day"
                                type="number"
                                min="1"
                                max="31"
                                defaultValue={card?.due_day}
                                required
                            />
                            <InputError message={errors.due_day} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="payment_account_id">
                            Conta usada para pagar a fatura
                        </Label>
                        <input
                            type="hidden"
                            name="payment_account_id"
                            value={
                                accountSelection === 'none'
                                    ? ''
                                    : accountSelection
                            }
                        />
                        <Select
                            value={accountSelection}
                            onValueChange={setAccountSelection}
                        >
                            <SelectTrigger
                                id="payment_account_id"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Não informada
                                </SelectItem>
                                {accountOptions.map((account) => (
                                    <SelectItem
                                        key={account.id}
                                        value={String(account.id)}
                                    >
                                        {account.name}
                                        {account.is_active ? '' : ' (inativa)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.payment_account_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="invoice_payment_method">
                            Forma padrão de pagamento da fatura
                        </Label>
                        <Select
                            name="invoice_payment_method"
                            defaultValue={
                                card?.invoice_payment_method ??
                                invoicePaymentMethods[0]?.value
                            }
                            required
                        >
                            <SelectTrigger
                                id="invoice_payment_method"
                                className="w-full"
                            >
                                <SelectValue placeholder="Selecione a forma de pagamento" />
                            </SelectTrigger>
                            <SelectContent>
                                {invoicePaymentMethods.map((method) => (
                                    <SelectItem
                                        key={method.value}
                                        value={method.value}
                                    >
                                        {method.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-muted-foreground text-xs">
                            Exemplo: a fatura do cartão Tumelero é paga por
                            boleto.
                        </p>
                        <InputError message={errors.invoice_payment_method} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="payment_instructions">
                            Instruções de pagamento
                        </Label>
                        <Input
                            id="payment_instructions"
                            name="payment_instructions"
                            defaultValue={card?.payment_instructions ?? ''}
                            placeholder="Ex.: boleto emitido no aplicativo da loja"
                            maxLength={500}
                        />
                        <p className="text-muted-foreground text-xs">
                            Informação padrão para facilitar o pagamento; cada
                            fatura poderá ser ajustada depois.
                        </p>
                        <InputError message={errors.payment_instructions} />
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
                            {card ? 'Salvar alterações' : 'Cadastrar cartão'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
