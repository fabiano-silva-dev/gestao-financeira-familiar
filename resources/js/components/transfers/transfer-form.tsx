import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import TransferController from '@/actions/App/Http/Controllers/TransferController';
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
import { index } from '@/routes/transfers';
import type { Transfer, TransferAccountOption } from '@/types';

type Props = {
    transfer?: Transfer;
    accountOptions: TransferAccountOption[];
    defaultDate?: string;
};

export default function TransferForm({
    transfer,
    accountOptions,
    defaultDate,
}: Props) {
    const [sourceAccount, setSourceAccount] = useState(
        transfer ? String(transfer.source_account_id) : '',
    );
    const [destinationAccount, setDestinationAccount] = useState(
        transfer ? String(transfer.destination_account_id) : '',
    );
    const form = transfer
        ? TransferController.update.form(transfer.id)
        : TransferController.store.form();

    const changeSourceAccount = (value: string) => {
        setSourceAccount(value);

        if (destinationAccount === value) {
            setDestinationAccount('');
        }
    };

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={!transfer}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="transaction_date">Data</Label>
                            <Input
                                id="transaction_date"
                                name="transaction_date"
                                type="date"
                                defaultValue={
                                    transfer?.transaction_date ?? defaultDate
                                }
                                required
                            />
                            <InputError message={errors.transaction_date} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="amount">Valor</Label>
                            <Input
                                id="amount"
                                name="amount"
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0.01"
                                defaultValue={transfer?.amount}
                                placeholder="0,00"
                                required
                            />
                            <InputError message={errors.amount} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="description">Descrição</Label>
                        <Input
                            id="description"
                            name="description"
                            defaultValue={transfer?.description}
                            placeholder="Ex.: Reserva para a conta digital"
                            maxLength={160}
                            required
                            autoFocus
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="source_account_id">
                                Conta de origem
                            </Label>
                            <Select
                                name="source_account_id"
                                value={sourceAccount}
                                onValueChange={changeSourceAccount}
                                required
                            >
                                <SelectTrigger
                                    id="source_account_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Selecione a origem" />
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
                            <InputError message={errors.source_account_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="destination_account_id">
                                Conta de destino
                            </Label>
                            <Select
                                name="destination_account_id"
                                value={destinationAccount}
                                onValueChange={setDestinationAccount}
                                required
                            >
                                <SelectTrigger
                                    id="destination_account_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Selecione o destino" />
                                </SelectTrigger>
                                <SelectContent>
                                    {accountOptions
                                        .filter(
                                            (account) =>
                                                String(account.id) !==
                                                sourceAccount,
                                        )
                                        .map((account) => (
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
                            <InputError
                                message={errors.destination_account_id}
                            />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="status">Situação</Label>
                        <Select
                            name="status"
                            defaultValue={transfer?.status ?? 'confirmed'}
                            required
                        >
                            <SelectTrigger id="status" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="confirmed">
                                    Confirmada — altera os saldos agora
                                </SelectItem>
                                <SelectItem value="planned">
                                    Planejada — ainda não altera os saldos
                                </SelectItem>
                                {transfer?.status === 'cancelled' && (
                                    <SelectItem value="cancelled">
                                        Cancelada — sem efeito nos saldos
                                    </SelectItem>
                                )}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.status} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="notes">Observações</Label>
                        <Input
                            id="notes"
                            name="notes"
                            defaultValue={transfer?.notes ?? ''}
                            placeholder="Informações opcionais"
                            maxLength={2000}
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <p className="text-muted-foreground text-xs">
                        A transferência cria uma saída e uma entrada vinculadas.
                        Ela movimenta as contas, mas não é receita nem despesa
                        da família.
                    </p>

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
                            {transfer
                                ? 'Salvar alterações'
                                : 'Cadastrar transferência'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
