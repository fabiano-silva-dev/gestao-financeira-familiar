import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    CircleAlert,
    FileSpreadsheet,
    Link2,
    Plus,
    RotateCcw,
    Sparkles,
    WalletCards,
} from 'lucide-react';
import { useState } from 'react';
import CardStatementReconciliationController from '@/actions/App/Http/Controllers/CardStatementReconciliationController';
import CreditCardInvoiceController from '@/actions/App/Http/Controllers/CreditCardInvoiceController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import { index } from '@/routes/credit-card-invoices';
import { createExpense } from '@/routes/transactions';
import type {
    CreditCardInvoice,
    FinancialEntryReferenceOption,
    PaymentMethodOption,
} from '@/types';

type Props = {
    invoice: CreditCardInvoice;
    accountOptions: FinancialEntryReferenceOption[];
    paymentMethods: PaymentMethodOption[];
    defaultPaymentAccountId: number | null;
    defaultPaymentMethod: string;
    unlinkedPayments: CreditCardInvoice['payments'];
    defaultPaymentDate: string;
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const date = new Intl.DateTimeFormat('pt-BR', {
    timeZone: 'UTC',
});

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

function formatDate(value: string) {
    return date.format(new Date(`${value}T00:00:00Z`));
}

function confidenceVariant(confidence: 'high' | 'medium' | 'low') {
    if (confidence === 'high') return 'secondary' as const;
    return 'outline' as const;
}

export default function CreditCardInvoiceShow() {
    const {
        invoice,
        accountOptions,
        paymentMethods,
        defaultPaymentAccountId,
        defaultPaymentMethod,
        defaultPaymentDate,
        unlinkedPayments = [],
    } = usePage<Props>().props;
    const [accountId, setAccountId] = useState(
        defaultPaymentAccountId ? String(defaultPaymentAccountId) : '',
    );
    const [paymentMethod, setPaymentMethod] = useState(defaultPaymentMethod);
    const installments = invoice.installments ?? [];
    const statementEntries = invoice.statement_entries ?? [];
    const payments = invoice.payments ?? [];
    const [selectedInstallments, setSelectedInstallments] = useState<
        Record<number, string>
    >(() =>
        statementEntries.reduce<Record<number, string>>((selected, entry) => {
            const suggestion = entry.candidates.find(
                (candidate) => candidate.is_suggestion,
            );

            if (suggestion) {
                selected[entry.id] = String(suggestion.installment_id);
            }

            return selected;
        }, {}),
    );

    return (
        <>
            <Head title={`Fatura ${invoice.credit_card_name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="mb-2"
                            asChild
                        >
                            <Link href={index()}>
                                <ArrowLeft />
                                Voltar para faturas
                            </Link>
                        </Button>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {invoice.credit_card_name} · final{' '}
                            {invoice.credit_card_last_four}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Fecha em {formatDate(invoice.closing_date)} · vence
                            em {formatDate(invoice.due_date)}
                        </p>
                    </div>
                    <Badge
                        variant={
                            invoice.status === 'overdue'
                                ? 'destructive'
                                : 'outline'
                        }
                    >
                        {invoice.status_label}
                    </Badge>
                </div>

                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Fatura</CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold tabular-nums">
                            {currency.format(
                                Number(
                                    invoice.net_invoice_amount,
                                ),
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Estornos</CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold tabular-nums">
                            {currency.format(Number(invoice.refund_amount))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Pago</CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold tabular-nums">
                            {currency.format(Number(invoice.paid_amount))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Em aberto</CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold tabular-nums">
                            {currency.format(
                                Number(invoice.outstanding_amount),
                            )}
                        </CardContent>
                    </Card>
                </div>

                {invoice.can_close && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Fechar fatura</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...CreditCardInvoiceController.close.form(
                                    invoice.id,
                                )}
                                options={{ preserveScroll: true }}
                                className="grid gap-4 md:grid-cols-[1fr_auto] md:items-end"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="statement_amount">
                                                Valor informado pela operadora
                                            </Label>
                                            <Input
                                                id="statement_amount"
                                                name="statement_amount"
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                defaultValue={
                                                    invoice.calculated_amount
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors.statement_amount
                                                }
                                            />
                                        </div>
                                        <Button disabled={processing}>
                                            <CheckCircle2 />
                                            Fechar fatura
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Compras e parcelas</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {installments.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Nenhuma parcela nesta fatura.
                            </p>
                        ) : (
                            installments.map((installment) => (
                                <div
                                    key={installment.id}
                                    className="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {installment.description}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            Parcela{' '}
                                            {installment.installment_number}/
                                            {installment.total_installments} ·
                                            compra em{' '}
                                            {formatDate(
                                                installment.transaction_date,
                                            )}
                                            {installment.category_name
                                                ? ` · ${installment.category_name}`
                                                : ''}
                                            {installment.family_member_name
                                                ? ` · ${installment.family_member_name}`
                                                : ''}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <Badge variant="outline">
                                            {installment.status_label}
                                        </Badge>
                                        <p className="font-semibold tabular-nums">
                                            {currency.format(
                                                Number(installment.amount),
                                            )}
                                        </p>
                                    </div>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                {statementEntries.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileSpreadsheet className="size-5" />
                                Linhas importadas da operadora
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {statementEntries.map((entry) => {
                                const suggestion = entry.candidates.find(
                                    (candidate) => candidate.is_suggestion,
                                );
                                const selected =
                                    selectedInstallments[entry.id] ?? '';

                                return (
                                    <div
                                        key={entry.id}
                                        className="rounded-lg border p-4"
                                    >
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {entry.description}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    Compra em{' '}
                                                    {formatDate(
                                                        entry.purchased_on,
                                                    )}
                                                    {entry.installment_number &&
                                                    entry.total_installments
                                                        ? ` · parcela ${entry.installment_number}/${entry.total_installments}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                <Badge variant="outline">
                                                    {entry.is_reconciled
                                                        ? 'Conciliada'
                                                        : 'Pendente'}
                                                </Badge>
                                                <p className="font-semibold tabular-nums">
                                                    {currency.format(
                                                        Number(entry.amount),
                                                    )}
                                                </p>
                                            </div>
                                        </div>

                                        {entry.linked_installment ? (
                                            <div className="bg-positive/5 border-positive/20 mt-4 flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between">
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        Vinculada a{' '}
                                                        {
                                                            entry
                                                                .linked_installment
                                                                .description
                                                        }
                                                    </p>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        Parcela{' '}
                                                        {
                                                            entry
                                                                .linked_installment
                                                                .installment_number
                                                        }
                                                        /
                                                        {
                                                            entry
                                                                .linked_installment
                                                                .total_installments
                                                        }{' '}
                                                        · compra em{' '}
                                                        {formatDate(
                                                            entry
                                                                .linked_installment
                                                                .transaction_date,
                                                        )}
                                                    </p>
                                                    {entry.reconciled_at && (
                                                        <p className="text-muted-foreground mt-1 text-xs">
                                                            Conciliada em{' '}
                                                            {dateTime.format(
                                                                new Date(
                                                                    entry.reconciled_at,
                                                                ),
                                                            )}
                                                            {entry.reconciled_by_name
                                                                ? ` por ${entry.reconciled_by_name}`
                                                                : ''}
                                                        </p>
                                                    )}
                                                </div>
                                                <Form
                                                    {...CardStatementReconciliationController.destroy.form(
                                                        {
                                                            invoice: invoice.id,
                                                            entry: entry.id,
                                                        },
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            <RotateCcw />
                                                            Desfazer
                                                        </Button>
                                                    )}
                                                </Form>
                                            </div>
                                        ) : (
                                            <>
                                                {suggestion && (
                                                    <div className="bg-primary/5 border-primary/15 mt-4 rounded-lg border p-3">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Sparkles className="text-primary size-4" />
                                                            <p className="text-sm font-medium">
                                                                Melhor sugestão
                                                            </p>
                                                            <Badge
                                                                variant={confidenceVariant(
                                                                    suggestion.confidence,
                                                                )}
                                                            >
                                                                {
                                                                    suggestion.confidence_label
                                                                }
                                                            </Badge>
                                                            <span className="text-muted-foreground text-xs">
                                                                {
                                                                    suggestion.score
                                                                }
                                                                %
                                                            </span>
                                                        </div>
                                                        <p className="mt-2 text-sm">
                                                            {
                                                                suggestion.description
                                                            }
                                                        </p>
                                                        <p className="text-muted-foreground mt-1 text-xs">
                                                            Parcela{' '}
                                                            {
                                                                suggestion.installment_number
                                                            }
                                                            /
                                                            {
                                                                suggestion.total_installments
                                                            }{' '}
                                                            · compra em{' '}
                                                            {formatDate(
                                                                suggestion.transaction_date,
                                                            )}
                                                        </p>
                                                    </div>
                                                )}

                                                {entry.candidates.length > 0 ? (
                                                    <Form
                                                        {...CardStatementReconciliationController.store.form(
                                                            {
                                                                invoice:
                                                                    invoice.id,
                                                                entry: entry.id,
                                                            },
                                                        )}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                        className="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start"
                                                    >
                                                        {({
                                                            processing,
                                                            errors,
                                                        }) => (
                                                            <>
                                                                <div>
                                                                    <input
                                                                        type="hidden"
                                                                        name="transaction_installment_id"
                                                                        value={
                                                                            selected
                                                                        }
                                                                    />
                                                                    <Select
                                                                        value={
                                                                            selected
                                                                        }
                                                                        onValueChange={(
                                                                            value,
                                                                        ) =>
                                                                            setSelectedInstallments(
                                                                                (
                                                                                    current,
                                                                                ) => ({
                                                                                    ...current,
                                                                                    [entry.id]:
                                                                                        value,
                                                                                }),
                                                                            )
                                                                        }
                                                                    >
                                                                        <SelectTrigger className="w-full">
                                                                            <SelectValue placeholder="Selecione uma compra compatível" />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            {entry.candidates.map(
                                                                                (
                                                                                    candidate,
                                                                                ) => (
                                                                                    <SelectItem
                                                                                        key={
                                                                                            candidate.installment_id
                                                                                        }
                                                                                        value={String(
                                                                                            candidate.installment_id,
                                                                                        )}
                                                                                    >
                                                                                        {
                                                                                            candidate.description
                                                                                        }{' '}
                                                                                        ·{' '}
                                                                                        parcela{' '}
                                                                                        {
                                                                                            candidate.installment_number
                                                                                        }
                                                                                        /
                                                                                        {
                                                                                            candidate.total_installments
                                                                                        }{' '}
                                                                                        ·{' '}
                                                                                        {formatDate(
                                                                                            candidate.transaction_date,
                                                                                        )}
                                                                                    </SelectItem>
                                                                                ),
                                                                            )}
                                                                        </SelectContent>
                                                                    </Select>
                                                                    <InputError
                                                                        message={
                                                                            errors.transaction_installment_id
                                                                        }
                                                                        className="mt-2"
                                                                    />
                                                                </div>
                                                                <Button
                                                                    disabled={
                                                                        processing ||
                                                                        selected ===
                                                                            ''
                                                                    }
                                                                >
                                                                    <Link2 />
                                                                    Conciliar
                                                                </Button>
                                                            </>
                                                        )}
                                                    </Form>
                                                ) : (
                                                    <div className="mt-4 flex flex-col gap-3 rounded-lg border border-dashed p-3 sm:flex-row sm:items-center sm:justify-between">
                                                        <div className="flex items-start gap-2">
                                                            <CircleAlert className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                                                            <div>
                                                                <p className="text-sm font-medium">
                                                                    Nenhuma
                                                                    parcela
                                                                    compatível
                                                                </p>
                                                                <p className="text-muted-foreground mt-1 text-xs">
                                                                    Cadastre a
                                                                    compra no
                                                                    cartão e
                                                                    volte para
                                                                    confirmar o
                                                                    vínculo.
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={createExpense()}
                                                            >
                                                                <Plus />
                                                                Nova compra
                                                            </Link>
                                                        </Button>
                                                    </div>
                                                )}
                                            </>
                                        )}
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                )}

                {unlinkedPayments.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Pagamentos aguardando fatura</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-muted-foreground text-sm">
                                Estes pagamentos já saíram da conta e estão conciliados
                                com o cartão, mas ainda não pertencem a uma fatura.
                            </p>
                            {unlinkedPayments.map((payment) => (
                                <div
                                    key={payment.id}
                                    className="flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {payment.account_name}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {formatDate(payment.paid_on)} ·{' '}
                                            {payment.payment_method_label}
                                        </p>
                                        {payment.notes && (
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {payment.notes}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <p className="font-semibold tabular-nums">
                                            {currency.format(Number(payment.amount))}
                                        </p>
                                        <Form
                                            {...CreditCardInvoiceController.linkPayment.form(
                                                {
                                                    invoice: invoice.id,
                                                    payment: payment.id,
                                                },
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled={processing}
                                                >
                                                    <Link2 />
                                                    Vincular a esta fatura
                                                </Button>
                                            )}
                                        </Form>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {invoice.can_pay && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Registrar pagamento</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...CreditCardInvoiceController.pay.form(
                                    invoice.id,
                                )}
                                options={{ preserveScroll: true }}
                                className="grid gap-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="financial_account_id">
                                                    Conta de pagamento
                                                </Label>
                                                <input
                                                    type="hidden"
                                                    name="financial_account_id"
                                                    value={accountId}
                                                />
                                                <Select
                                                    value={accountId}
                                                    onValueChange={setAccountId}
                                                    required
                                                >
                                                    <SelectTrigger
                                                        id="financial_account_id"
                                                        className="w-full"
                                                    >
                                                        <SelectValue placeholder="Selecione a conta" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {accountOptions.map(
                                                            (account) => (
                                                                <SelectItem
                                                                    key={
                                                                        account.id
                                                                    }
                                                                    value={String(
                                                                        account.id,
                                                                    )}
                                                                >
                                                                    {
                                                                        account.name
                                                                    }
                                                                    {account.is_active
                                                                        ? ''
                                                                        : ' (inativa)'}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                <InputError
                                                    message={
                                                        errors.financial_account_id
                                                    }
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="payment_method">
                                                    Forma de pagamento
                                                </Label>
                                                <input
                                                    type="hidden"
                                                    name="payment_method"
                                                    value={paymentMethod}
                                                />
                                                <Select
                                                    value={paymentMethod}
                                                    onValueChange={
                                                        setPaymentMethod
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id="payment_method"
                                                        className="w-full"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {paymentMethods.map(
                                                            (method) => (
                                                                <SelectItem
                                                                    key={
                                                                        method.value
                                                                    }
                                                                    value={
                                                                        method.value
                                                                    }
                                                                >
                                                                    {
                                                                        method.label
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                <InputError
                                                    message={
                                                        errors.payment_method
                                                    }
                                                />
                                            </div>
                                        </div>

                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="paid_on">
                                                    Data do pagamento
                                                </Label>
                                                <Input
                                                    id="paid_on"
                                                    name="paid_on"
                                                    type="date"
                                                    defaultValue={
                                                        defaultPaymentDate
                                                    }
                                                    required
                                                />
                                                <InputError
                                                    message={errors.paid_on}
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="amount">
                                                    Valor pago
                                                </Label>
                                                <Input
                                                    id="amount"
                                                    name="amount"
                                                    type="number"
                                                    step="0.01"
                                                    min="0.01"
                                                    max={
                                                        invoice.outstanding_amount
                                                    }
                                                    defaultValue={
                                                        invoice.outstanding_amount
                                                    }
                                                    required
                                                />
                                                <InputError
                                                    message={errors.amount}
                                                />
                                            </div>
                                        </div>

                                        {invoice.payment_instructions && (
                                            <div className="bg-muted/50 rounded-lg p-3 text-sm">
                                                <p className="font-medium">
                                                    Instruções cadastradas
                                                </p>
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    {
                                                        invoice.payment_instructions
                                                    }
                                                </p>
                                            </div>
                                        )}

                                        <div className="grid gap-2">
                                            <Label htmlFor="notes">
                                                Observações
                                            </Label>
                                            <Input
                                                id="notes"
                                                name="notes"
                                                placeholder="Opcional"
                                                maxLength={2000}
                                            />
                                            <InputError
                                                message={errors.notes}
                                            />
                                        </div>

                                        <div className="flex justify-end">
                                            <Button disabled={processing}>
                                                <WalletCards />
                                                Registrar pagamento
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                )}

                {payments.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Pagamentos registrados</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {payments.map((payment) => (
                                <div
                                    key={payment.id}
                                    className="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {payment.account_name}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {formatDate(payment.paid_on)} ·{' '}
                                            {payment.payment_method_label}
                                        </p>
                                        {payment.notes && (
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {payment.notes}
                                            </p>
                                        )}
                                    </div>
                                    <p className="font-semibold tabular-nums">
                                        {currency.format(
                                            Number(payment.amount),
                                        )}
                                    </p>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

CreditCardInvoiceShow.layout = {
    breadcrumbs: [
        {
            title: 'Faturas',
            href: index(),
        },
    ],
};
