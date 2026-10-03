import { Form, Head, router } from '@inertiajs/react';
import { RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EntryOriginBanner } from '@/components/transactions/entry-origin-banner';
import EntryTypeSwitcher from '@/components/transactions/entry-type-switcher';
import FinancialEntryForm from '@/components/transactions/financial-entry-form';
import TransferForm from '@/components/transfers/transfer-form';
import FinancialTransactionController from '@/actions/App/Http/Controllers/FinancialTransactionController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { index, mergeInstallmentPlan } from '@/routes/transactions';
import type {
    FinancialEntry,
    FinancialEntryReferenceOption,
    FinancialEntryType,
    PaymentMethodOption,
} from '@/types';

type TypeOption = {
    value: string;
    label: string;
};

type Props = {
    entry: FinancialEntry;
    typeOptions: TypeOption[];
    accountOptions: FinancialEntryReferenceOption[];
    cardOptions: FinancialEntryReferenceOption[];
    categoryOptions: FinancialEntryReferenceOption[];
    memberOptions: FinancialEntryReferenceOption[];
    paymentMethods: PaymentMethodOption[];
    refundInvoiceOptions: Array<{ id: number; label: string }>;
    installmentPlan: {
        canonical_id: number;
        is_canonical: boolean;
        duplicates: Array<{
            id: number;
            description: string;
            amount: string;
        }>;
    } | null;
};

export default function TransactionsEdit({
    entry,
    typeOptions,
    accountOptions,
    refundInvoiceOptions,
    installmentPlan,
    ...formProps
}: Props) {
    const [type, setType] = useState<FinancialEntryType>(entry.type);
    const isTransfer = type === 'transfer';
    const typeLabel =
        typeOptions.find((option) => option.value === type)?.label ??
        entry.type_label;
    const [refundDestination, setRefundDestination] = useState<
        'account' | 'credit_card'
    >('account');
    const [refundAccountId, setRefundAccountId] = useState(
        accountOptions.find((account) => account.is_active)
            ? String(accountOptions.find((account) => account.is_active)?.id)
            : '',
    );
    const [refundInvoiceId, setRefundInvoiceId] = useState(
        refundInvoiceOptions[0] ? String(refundInvoiceOptions[0].id) : '',
    );
    const [confirmMergeOpen, setConfirmMergeOpen] = useState(false);
    const [mergingPlan, setMergingPlan] = useState(false);
    const [mergeError, setMergeError] = useState<string | null>(null);
    const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false);
    const [deletingEntry, setDeletingEntry] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    function mergePlan() {
        setMergingPlan(true);
        setMergeError(null);
        router.post(mergeInstallmentPlan.url(entry.id), {}, {
            onError: (errors) => {
                setMergeError(
                    errors.entry ??
                        'Não foi possível unir as compras parceladas.',
                );
            },
            onFinish: () => setMergingPlan(false),
        });
    }

    function deleteEntry() {
        setDeletingEntry(true);
        setDeleteError(null);
        router.delete(`/lancamentos/${entry.id}`, {
            onError: (errors) => {
                setDeleteError(
                    errors.entry ??
                        errors.reconciliation ??
                        Object.values(errors)[0] ??
                        'Não foi possível excluir este lançamento.',
                );
            },
            onFinish: () => setDeletingEntry(false),
        });
    }

    return (
        <>
            <Head title={`Editar ${typeLabel.toLocaleLowerCase('pt-BR')}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Editar {typeLabel.toLocaleLowerCase('pt-BR')}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {isTransfer
                                ? 'Atualize a movimentação entre contas próprias.'
                                : 'Atualize o lançamento e seu impacto financeiro.'}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={() => {
                            setDeleteError(null);
                            setConfirmDeleteOpen(true);
                        }}
                    >
                        <Trash2 />
                        Excluir lançamento
                    </Button>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Dados do lançamento</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <EntryOriginBanner entry={entry} />
                        {installmentPlan != null && (
                            <div className="border-primary/20 bg-primary/5 rounded-lg border p-3 text-sm">
                                <p className="font-medium">
                                    Compra parcelada repetida
                                </p>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Outra fatura lançou esta mesma compra de
                                    novo e a parcela seguinte não foi
                                    reconhecida como continuação do
                                    parcelamento.
                                </p>
                                <Button
                                    type="button"
                                    className="mt-3"
                                    onClick={() => {
                                        setMergeError(null);
                                        setConfirmMergeOpen(true);
                                    }}
                                >
                                    Unir em uma só compra
                                </Button>
                            </div>
                        )}
                        <EntryTypeSwitcher
                            value={type}
                            options={typeOptions}
                            onChange={setType}
                        />
                        {isTransfer ? (
                            <TransferForm
                                transfer={{
                                    id: entry.id,
                                    transaction_date: entry.transaction_date,
                                    description: entry.description,
                                    amount: entry.amount,
                                    source_account_id:
                                        entry.source_account_id ??
                                        entry.financial_account_id ??
                                        0,
                                    source_account_name:
                                        entry.source_account_name ??
                                        entry.financial_account_name ??
                                        '',
                                    destination_account_id:
                                        entry.destination_account_id ?? 0,
                                    destination_account_name:
                                        entry.destination_account_name ?? '',
                                    status: entry.status,
                                    status_label: entry.status_label,
                                    notes: entry.notes,
                                }}
                                accountOptions={accountOptions}
                            />
                        ) : (
                            <FinancialEntryForm
                                entry={entry}
                                entryType={type}
                                accountOptions={accountOptions}
                                {...formProps}
                            />
                        )}
                    </CardContent>
                </Card>

                {entry.type === 'expense' && (
                    <Card className="max-w-3xl">
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between gap-3">
                                <span>Reembolsos</span>
                                <Badge variant="outline">
                                    {entry.refund_status_label}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="rounded-lg border p-3">
                                    <p className="text-muted-foreground text-xs">
                                        Valor original
                                    </p>
                                    <p className="font-semibold tabular-nums">
                                        R$ {Number(entry.original_amount).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                                    </p>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <p className="text-muted-foreground text-xs">
                                        Já reembolsado
                                    </p>
                                    <p className="font-semibold tabular-nums">
                                        R$ {Number(entry.refunded_amount).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                                    </p>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <p className="text-muted-foreground text-xs">
                                        Despesa líquida
                                    </p>
                                    <p className="font-semibold tabular-nums">
                                        R$ {Number(entry.net_amount).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                                    </p>
                                </div>
                            </div>

                            {entry.refunds.length > 0 && (
                                <div className="space-y-2">
                                    {entry.refunds.map((refund) => (
                                        <div
                                            key={refund.id}
                                            className="rounded-lg border p-3 text-sm"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <p className="font-medium">
                                                        R$ {Number(refund.amount).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                                                        {' · '}
                                                        {refund.destination_label}
                                                    </p>
                                                    <p className="text-muted-foreground text-xs">
                                                        {refund.refunded_on}
                                                        {' · '}
                                                        {refund.origin_label}
                                                        {refund.movement_reconciled
                                                            ? ' · movimento conciliado'
                                                            : ''}
                                                    </p>
                                                </div>
                                                <Badge variant="secondary">
                                                    {refund.status_label}
                                                </Badge>
                                            </div>
                                            {refund.movement_import_filename && (
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    Arquivo: {refund.movement_import_filename}
                                                </p>
                                            )}
                                            {refund.notes && (
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    {refund.notes}
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}

                            {Number(entry.refundable_amount) > 0 && (
                                <Form
                                    {...FinancialTransactionController.refund.form(
                                        entry.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                    className="grid gap-4 border-t pt-5"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <h3 className="font-medium">
                                                Registrar reembolso
                                            </h3>
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="refund_amount">
                                                        Valor
                                                    </Label>
                                                    <Input
                                                        id="refund_amount"
                                                        name="amount"
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        max={entry.refundable_amount}
                                                        defaultValue={entry.refundable_amount}
                                                        required
                                                    />
                                                    <InputError message={errors.amount} />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="refunded_on">
                                                        Data
                                                    </Label>
                                                    <Input
                                                        id="refunded_on"
                                                        name="refunded_on"
                                                        type="date"
                                                        defaultValue={
                                                            new Date()
                                                                .toISOString()
                                                                .slice(0, 10)
                                                        }
                                                        required
                                                    />
                                                    <InputError message={errors.refunded_on} />
                                                </div>
                                            </div>

                                            <div className="grid gap-2">
                                                <Label>Destino do dinheiro</Label>
                                                <input
                                                    type="hidden"
                                                    name="destination_type"
                                                    value={refundDestination}
                                                />
                                                <Select
                                                    value={refundDestination}
                                                    onValueChange={(value) =>
                                                        setRefundDestination(
                                                            value as 'account' | 'credit_card',
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="account">
                                                            Conta financeira
                                                        </SelectItem>
                                                        {entry.credit_card_id !== null &&
                                                            refundInvoiceOptions.length > 0 && (
                                                                <SelectItem value="credit_card">
                                                                    Estorno no cartão/fatura
                                                                </SelectItem>
                                                            )}
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            {refundDestination === 'account' ? (
                                                <div className="grid gap-2">
                                                    <Label>Conta de destino</Label>
                                                    <input
                                                        type="hidden"
                                                        name="destination_account_id"
                                                        value={refundAccountId}
                                                    />
                                                    <Select
                                                        value={refundAccountId}
                                                        onValueChange={setRefundAccountId}
                                                    >
                                                        <SelectTrigger className="w-full">
                                                            <SelectValue placeholder="Selecione a conta" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {accountOptions.map((account) => (
                                                                <SelectItem
                                                                    key={account.id}
                                                                    value={String(account.id)}
                                                                >
                                                                    {account.label ?? account.name}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError message={errors.destination_account_id} />
                                                </div>
                                            ) : (
                                                <div className="grid gap-2">
                                                    <Label>Fatura do estorno</Label>
                                                    <input
                                                        type="hidden"
                                                        name="credit_card_invoice_id"
                                                        value={refundInvoiceId}
                                                    />
                                                    <Select
                                                        value={refundInvoiceId}
                                                        onValueChange={setRefundInvoiceId}
                                                    >
                                                        <SelectTrigger className="w-full">
                                                            <SelectValue placeholder="Selecione a fatura" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {refundInvoiceOptions.map((invoice) => (
                                                                <SelectItem
                                                                    key={invoice.id}
                                                                    value={String(invoice.id)}
                                                                >
                                                                    {invoice.label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError message={errors.credit_card_invoice_id} />
                                                </div>
                                            )}

                                            <div className="grid gap-2">
                                                <Label htmlFor="refund_notes">
                                                    Observações
                                                </Label>
                                                <Input
                                                    id="refund_notes"
                                                    name="notes"
                                                    placeholder="Opcional"
                                                    maxLength={2000}
                                                />
                                                <InputError message={errors.notes} />
                                            </div>

                                            <div className="flex justify-end">
                                                <Button disabled={processing}>
                                                    <RotateCcw />
                                                    Registrar reembolso
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog
                open={confirmMergeOpen}
                onOpenChange={(open) => {
                    if (!mergingPlan) {
                        setConfirmMergeOpen(open);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Unir em uma só compra?</DialogTitle>
                        <DialogDescription>
                            As parcelas já conciliadas ficam na compra que tem
                            a parcela mais antiga. A compra repetida e as
                            parcelas projetadas em dobro são removidas. O valor
                            de cada parcela passa a ser o que veio na fatura.
                        </DialogDescription>
                    </DialogHeader>
                    <InputError message={mergeError ?? undefined} />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={mergingPlan}
                            onClick={() => setConfirmMergeOpen(false)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="button"
                            disabled={mergingPlan}
                            onClick={mergePlan}
                        >
                            {mergingPlan ? 'Unindo...' : 'Unir compras'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={confirmDeleteOpen}
                onOpenChange={(open) => {
                    if (!deletingEntry) {
                        setConfirmDeleteOpen(open);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {entry.financial_recurrence_id !== null
                                ? 'Excluir esta ocorrência?'
                                : 'Excluir lançamento?'}
                        </DialogTitle>
                        <DialogDescription>
                            {entry.financial_recurrence_id !== null
                                ? 'Somente esta ocorrência será removida. A recorrência continua, as outras datas permanecem e esta data não será gerada novamente.'
                                : 'O lançamento e seus movimentos de conta serão removidos. Se houver conciliação bancária, reembolso, fatura ou linha importada de cartão vinculada, a exclusão será bloqueada.'}
                            {' '}
                            {entry.description} · R$ {Number(entry.amount).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}.
                        </DialogDescription>
                    </DialogHeader>
                    <InputError message={deleteError ?? undefined} />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={deletingEntry}
                            onClick={() => setConfirmDeleteOpen(false)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={deletingEntry}
                            data-test="confirm-delete-entry"
                            onClick={deleteEntry}
                        >
                            {deletingEntry ? 'Excluindo...' : 'Excluir lançamento'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

TransactionsEdit.layout = {
    breadcrumbs: [
        { title: 'Lançamentos', href: index() },
        { title: 'Editar lançamento', href: index() },
    ],
};
