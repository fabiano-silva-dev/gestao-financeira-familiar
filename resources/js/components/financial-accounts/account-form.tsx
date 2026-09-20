import { Form, Link } from '@inertiajs/react';
import FinancialAccountController from '@/actions/App/Http/Controllers/FinancialAccountController';
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
import { index } from '@/routes/accounts';
import type { FinancialAccount, FinancialAccountTypeOption } from '@/types';

type Props = {
    account?: FinancialAccount;
    accountTypes: FinancialAccountTypeOption[];
};

export default function AccountForm({ account, accountTypes }: Props) {
    const form = account
        ? FinancialAccountController.update.form(account.id)
        : FinancialAccountController.store.form();

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={!account}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Nome da conta</Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={account?.name}
                            placeholder="Ex.: Sicredi principal"
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
                            defaultValue={account?.institution ?? ''}
                            placeholder="Ex.: Sicredi, Nubank ou dinheiro"
                            maxLength={120}
                        />
                        <InputError message={errors.institution} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="type">Tipo</Label>
                        <Select
                            name="type"
                            defaultValue={
                                account?.type ?? accountTypes[0]?.value
                            }
                            required
                        >
                            <SelectTrigger id="type" className="w-full">
                                <SelectValue placeholder="Selecione o tipo" />
                            </SelectTrigger>
                            <SelectContent>
                                {accountTypes.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.type} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="opening_balance">Saldo inicial</Label>
                        <Input
                            id="opening_balance"
                            name="opening_balance"
                            type="number"
                            inputMode="decimal"
                            step="0.01"
                            defaultValue={account?.opening_balance ?? '0.00'}
                            required
                        />
                        <p className="text-muted-foreground text-xs">
                            Esse valor inicia o saldo da conta e não será
                            tratado como receita.
                        </p>
                        <InputError message={errors.opening_balance} />
                    </div>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button variant="outline" asChild>
                            <Link href={index()}>Cancelar</Link>
                        </Button>
                        <Button disabled={processing}>
                            {account ? 'Salvar alterações' : 'Cadastrar conta'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
