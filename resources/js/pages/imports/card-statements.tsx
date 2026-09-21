import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowDownCircle,
    ArrowUpCircle,
    CreditCard,
    FileCheck2,
    FileSpreadsheet,
    History,
    Layers3,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import CardStatementImportController from '@/actions/App/Http/Controllers/CardStatementImportController';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card as UiCard,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { create as createCard } from '@/routes/credit-cards';
import { index } from '@/routes/imports/card-statements';
import type {
    CardStatementCardOption,
    CardStatementEntry,
    CardStatementImportHistoryItem,
} from '@/types';

type Props = {
    cardOptions: CardStatementCardOption[];
    imports: CardStatementImportHistoryItem[];
    entries: CardStatementEntry[];
    pendingEntriesCount: number;
    defaultReferenceMonth: string;
};

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const date = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

const month = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
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

function formatMonth(value: string) {
    const normalized = value.length === 7 ? `${value}-01` : value;

    return month.format(new Date(`${normalized}T00:00:00Z`));
}

function statusVariant(status: CardStatementImportHistoryItem['status']) {
    if (status === 'completed') return 'secondary' as const;
    if (status === 'failed') return 'destructive' as const;
    return 'outline' as const;
}

export default function CardStatementImportPage({
    cardOptions,
    imports,
    entries,
    pendingEntriesCount,
    defaultReferenceMonth,
}: Props) {
    const defaultCard = cardOptions.find((card) => card.is_active);

    return (
        <>
            <Head title="Importar fatura de cartão" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Fase 5 · Automação
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Importar fatura de cartão
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Centralize compras e parcelas recebidas da operadora
                            sem duplicar despesas.
                        </p>
                    </div>

                    <div className="rounded-lg border px-4 py-3">
                        <p className="text-muted-foreground text-xs uppercase">
                            Aguardando conferência
                        </p>
                        <p className="text-xl font-semibold tabular-nums">
                            {pendingEntriesCount}
                        </p>
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(320px,0.9fr)]">
                    <UiCard>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileSpreadsheet className="size-5" />
                                Nova fatura
                            </CardTitle>
                            <CardDescription>
                                Informe o cartão e o mês de vencimento da fatura
                                antes de enviar o arquivo.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {cardOptions.length === 0 ? (
                                <div className="flex flex-col items-center gap-4 rounded-lg border border-dashed p-8 text-center">
                                    <CreditCard className="text-muted-foreground size-8" />
                                    <div>
                                        <p className="font-medium">
                                            Cadastre um cartão primeiro
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            Cada arquivo precisa ser associado
                                            ao cartão e à fatura corretos.
                                        </p>
                                    </div>
                                    <Button asChild>
                                        <Link href={createCard()}>
                                            Cadastrar cartão
                                        </Link>
                                    </Button>
                                </div>
                            ) : (
                                <Form
                                    {...CardStatementImportController.store.form()}
                                    options={{ preserveScroll: true }}
                                    resetOnSuccess
                                    className="space-y-6"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="credit_card_id">
                                                        Cartão
                                                    </Label>
                                                    <Select
                                                        name="credit_card_id"
                                                        defaultValue={String(
                                                            defaultCard?.id ??
                                                                cardOptions[0]
                                                                    ?.id,
                                                        )}
                                                        required
                                                    >
                                                        <SelectTrigger
                                                            id="credit_card_id"
                                                            className="w-full"
                                                        >
                                                            <SelectValue placeholder="Selecione o cartão" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {cardOptions.map(
                                                                (card) => (
                                                                    <SelectItem
                                                                        key={
                                                                            card.id
                                                                        }
                                                                        value={String(
                                                                            card.id,
                                                                        )}
                                                                    >
                                                                        {
                                                                            card.name
                                                                        }{' '}
                                                                        · final{' '}
                                                                        {
                                                                            card.last_four
                                                                        }
                                                                        {!card.is_active
                                                                            ? ' · inativo'
                                                                            : ''}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError
                                                        message={
                                                            errors.credit_card_id
                                                        }
                                                    />
                                                </div>

                                                <div className="grid gap-2">
                                                    <Label htmlFor="reference_month">
                                                        Mês de vencimento
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
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="amount_sign">
                                                    Como as compras aparecem no
                                                    arquivo?
                                                </Label>
                                                <Select
                                                    name="amount_sign"
                                                    defaultValue="positive"
                                                    required
                                                >
                                                    <SelectTrigger
                                                        id="amount_sign"
                                                        className="w-full"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="positive">
                                                            Positivas — ex. R$
                                                            89,90
                                                        </SelectItem>
                                                        <SelectItem value="negative">
                                                            Negativas — ex. -R$
                                                            89,90
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                <p className="text-muted-foreground text-xs">
                                                    O sistema normaliza compras
                                                    como débitos da fatura e
                                                    estornos como créditos.
                                                </p>
                                                <InputError
                                                    message={errors.amount_sign}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="file">
                                                    Arquivo CSV, XLS ou XLSX
                                                </Label>
                                                <Input
                                                    id="file"
                                                    name="file"
                                                    type="file"
                                                    accept=".csv,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                                    required
                                                />
                                                <p className="text-muted-foreground text-xs">
                                                    Limite de 10 MB. O arquivo
                                                    original fica armazenado em
                                                    área privada para auditoria.
                                                    Arquivos XLS binários
                                                    antigos devem ser salvos
                                                    como XLSX ou CSV.
                                                </p>
                                                <InputError
                                                    message={errors.file}
                                                />
                                            </div>

                                            <Button
                                                className="w-full sm:w-auto"
                                                disabled={processing}
                                            >
                                                <Upload />
                                                {processing
                                                    ? 'Processando fatura…'
                                                    : 'Importar fatura'}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            )}
                        </CardContent>
                    </UiCard>

                    <div className="space-y-4">
                        <Alert>
                            <ShieldCheck />
                            <AlertTitle>Sem duplicidades</AlertTitle>
                            <AlertDescription>
                                <p>
                                    Arquivos reenviados e linhas repetidas são
                                    reconhecidos por cartão e fatura.
                                </p>
                            </AlertDescription>
                        </Alert>
                        <Alert>
                            <Layers3 />
                            <AlertTitle>Parcelas preservadas</AlertTitle>
                            <AlertDescription>
                                <p>
                                    Uma linha 2/10 permanece vinculada à fatura
                                    como parcela 2/10; ela não vira uma nova
                                    compra independente.
                                </p>
                            </AlertDescription>
                        </Alert>
                        <Alert>
                            <FileCheck2 />
                            <AlertTitle>Conferência antes de lançar</AlertTitle>
                            <AlertDescription>
                                <p>
                                    As linhas entram em uma área intermediária.
                                    A etapa de conciliação fará o vínculo com
                                    compras existentes ou criará a compra pelo
                                    motor financeiro.
                                </p>
                            </AlertDescription>
                        </Alert>
                    </div>
                </div>

                <UiCard>
                    <CardHeader>
                        <CardTitle>Linhas importadas</CardTitle>
                        <CardDescription>
                            As 50 linhas mais recentes, preservando a parcela
                            informada pela operadora.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {entries.length === 0 ? (
                            <div className="flex flex-col items-center gap-3 py-10 text-center">
                                <FileSpreadsheet className="text-muted-foreground size-8" />
                                <p className="font-medium">
                                    Nenhuma fatura importada
                                </p>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Envie um arquivo estruturado para iniciar a
                                    conferência das faturas.
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y">
                                {entries.map((entry) => {
                                    const amount = Number(entry.amount);
                                    const isCredit = amount < 0;
                                    const AmountIcon = isCredit
                                        ? ArrowUpCircle
                                        : ArrowDownCircle;

                                    return (
                                        <div
                                            key={entry.id}
                                            className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="flex min-w-0 items-start gap-3">
                                                <AmountIcon
                                                    className={
                                                        isCredit
                                                            ? 'text-positive mt-0.5 size-5 shrink-0'
                                                            : 'text-destructive mt-0.5 size-5 shrink-0'
                                                    }
                                                />
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {entry.description}
                                                    </p>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {entry.card_name} ·
                                                        final{' '}
                                                        {entry.card_last_four} ·{' '}
                                                        {formatDate(
                                                            entry.purchased_on,
                                                        )}
                                                    </p>
                                                    <p className="text-muted-foreground mt-1 text-xs capitalize">
                                                        Fatura{' '}
                                                        {formatMonth(
                                                            entry.reference_month,
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="flex items-center justify-between gap-3 sm:justify-end">
                                                {entry.installment_number &&
                                                    entry.total_installments && (
                                                        <Badge variant="outline">
                                                            Parcela{' '}
                                                            {
                                                                entry.installment_number
                                                            }
                                                            /
                                                            {
                                                                entry.total_installments
                                                            }
                                                        </Badge>
                                                    )}
                                                <Badge variant="outline">
                                                    Pendente
                                                </Badge>
                                                <p
                                                    className={
                                                        isCredit
                                                            ? 'text-positive font-semibold tabular-nums'
                                                            : 'text-destructive font-semibold tabular-nums'
                                                    }
                                                >
                                                    {currency.format(amount)}
                                                </p>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </UiCard>

                <UiCard>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <History className="size-5" />
                            Histórico de arquivos
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {imports.length === 0 ? (
                            <p className="text-muted-foreground py-6 text-center text-sm">
                                Nenhuma fatura processada até agora.
                            </p>
                        ) : (
                            <div className="grid gap-3 lg:grid-cols-2">
                                {imports.map((item) => (
                                    <div
                                        key={item.id}
                                        className="rounded-lg border p-4"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {item.source_filename}
                                                </p>
                                                <p className="text-muted-foreground mt-1 text-xs capitalize">
                                                    {item.card_name} · final{' '}
                                                    {item.card_last_four}
                                                    {item.reference_month
                                                        ? ` · ${formatMonth(item.reference_month)}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={statusVariant(
                                                    item.status,
                                                )}
                                            >
                                                {item.status_label}
                                            </Badge>
                                        </div>

                                        {item.status === 'completed' ? (
                                            <>
                                                <div className="mt-4 grid grid-cols-3 gap-3 text-sm">
                                                    <div>
                                                        <p className="text-muted-foreground text-xs">
                                                            Lidas
                                                        </p>
                                                        <p className="font-semibold tabular-nums">
                                                            {item.total_records}
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <p className="text-muted-foreground text-xs">
                                                            Novas
                                                        </p>
                                                        <p className="font-semibold tabular-nums">
                                                            {
                                                                item.imported_records
                                                            }
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <p className="text-muted-foreground text-xs">
                                                            Duplicadas
                                                        </p>
                                                        <p className="font-semibold tabular-nums">
                                                            {
                                                                item.duplicate_records
                                                            }
                                                        </p>
                                                    </div>
                                                </div>
                                                {item.statement_amount && (
                                                    <p className="text-muted-foreground mt-3 border-t pt-3 text-xs">
                                                        Total do arquivo:{' '}
                                                        <span className="text-foreground font-medium">
                                                            {currency.format(
                                                                Number(
                                                                    item.statement_amount,
                                                                ),
                                                            )}
                                                        </span>
                                                        {item.statement_amount_applied ===
                                                            false &&
                                                            ' · fatura já fechada, valor preservado apenas no histórico'}
                                                    </p>
                                                )}
                                            </>
                                        ) : (
                                            item.error_message && (
                                                <p className="text-destructive mt-3 text-xs">
                                                    {item.error_message}
                                                </p>
                                            )
                                        )}

                                        {item.created_at && (
                                            <p className="text-muted-foreground mt-3 border-t pt-3 text-xs">
                                                Enviado em{' '}
                                                {dateTime.format(
                                                    new Date(item.created_at),
                                                )}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </UiCard>
            </div>
        </>
    );
}

CardStatementImportPage.layout = {
    breadcrumbs: [
        {
            title: 'Importar faturas',
            href: index(),
        },
    ],
};
