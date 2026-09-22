import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowDownCircle,
    ArrowUpCircle,
    CreditCard,
    FileUp,
    History,
    Landmark,
    ListChecks,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import { useState, type ChangeEvent } from 'react';
import InputError from '@/components/input-error';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
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
import { sortListing } from '@/lib/listing';
import { create as createAccount } from '@/routes/accounts';
import { create as createCard } from '@/routes/credit-cards';
import { index, store } from '@/routes/imports';
import { index as reconciliationIndex } from '@/routes/reconciliation';
import type {
    CardStatementCardOption,
    FinancialImportAccountOption,
    PdfLayoutOption,
    UnifiedImportEntry,
    UnifiedImportHistoryItem,
    UnifiedImportKind,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    accountOptions: FinancialImportAccountOption[];
    cardOptions: CardStatementCardOption[];
    pdfLayouts: PdfLayoutOption[];
    imports: UnifiedImportHistoryItem[];
    entries: UnifiedImportEntry[];
    pendingEntriesCount: number;
    defaultReferenceMonth: string;
    filters: ListingQueryState;
    hasRecords: boolean;
    kindOptions: ListingFilterOption[];
    statusOptions: ListingFilterOption[];
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

function formatLabel(extension: string) {
    if (['ofx', 'qfx'].includes(extension)) {
        return 'OFX';
    }

    if (extension === 'csv') {
        return 'CSV';
    }

    if (extension === 'pdf') {
        return 'PDF';
    }

    if (['xls', 'xlsx'].includes(extension)) {
        return 'Planilha';
    }

    return extension.toUpperCase();
}

function inferKind(extension: string): UnifiedImportKind | '' {
    if (['ofx', 'qfx', 'pdf'].includes(extension)) {
        return 'statement';
    }

    if (['xls', 'xlsx'].includes(extension)) {
        return 'invoice';
    }

    return '';
}

export default function ImportsIndex({
    accountOptions,
    cardOptions,
    pdfLayouts,
    imports,
    entries,
    pendingEntriesCount,
    defaultReferenceMonth,
    filters,
    hasRecords,
    kindOptions,
    statusOptions,
}: Props) {
    const listUrl = index.url();
    const onSort = (column: string) =>
        sortListing(
            listUrl,
            filters,
            column,
            column === 'amount' || column === 'date' ? 'desc' : 'asc',
        );
    const defaultAccount = accountOptions.find((account) => account.is_active);
    const defaultCard = cardOptions.find((card) => card.is_active);
    const [fileName, setFileName] = useState('');
    const [extension, setExtension] = useState('');
    const [kind, setKind] = useState<UnifiedImportKind | ''>('');
    const [pdfLayout, setPdfLayout] = useState(
        pdfLayouts[0]?.value ?? 'banrisul_current_account',
    );
    const needsKindChoice = extension === 'csv';
    const needsPdfLayout = extension === 'pdf';
    const canSubmit = fileName !== '' && kind !== '';

    const onFileChange = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];

        if (!file) {
            setFileName('');
            setExtension('');
            setKind('');

            return;
        }

        const nextExtension = (
            file.name.split('.').pop() ?? ''
        ).toLowerCase();
        setFileName(file.name);
        setExtension(nextExtension);
        setKind(inferKind(nextExtension));
    };

    return (
        <>
            <Head title="Importações" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Fase 5 · Automação
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Importações
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Envie o arquivo primeiro. O formato sai da
                            extensão; o PDF pede o layout do banco. Depois,
                            tudo segue para a conciliação.
                        </p>
                    </div>

                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <div className="rounded-lg border px-4 py-3">
                            <p className="text-muted-foreground text-xs uppercase">
                                Aguardando conciliação
                            </p>
                            <p className="text-xl font-semibold tabular-nums">
                                {pendingEntriesCount}
                            </p>
                        </div>
                        <Button variant="outline" asChild>
                            <Link href={reconciliationIndex()}>
                                <ListChecks />
                                Conciliar
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(320px,0.9fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileUp className="size-5" />
                                Novo arquivo
                            </CardTitle>
                            <CardDescription>
                                Extrato, fatura, OFX, CSV, PDF ou planilha
                                entram pelo mesmo fluxo.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                action={store.url()}
                                method="post"
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                onSuccess={() => {
                                    setFileName('');
                                    setExtension('');
                                    setKind('');
                                }}
                                className="space-y-6"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        {kind !== '' && (
                                            <input type="hidden" name="kind" value={kind} />
                                        )}
                                        <div className="grid gap-2">
                                            <Label htmlFor="file">
                                                Arquivo
                                            </Label>
                                            <label
                                                htmlFor="file"
                                                className="border-input hover:bg-muted/40 flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed px-4 py-8 text-center"
                                            >
                                                <Upload className="text-muted-foreground mb-2 size-6" />
                                                <span className="font-medium">
                                                    {fileName ||
                                                        'Solte o arquivo ou clique para escolher'}
                                                </span>
                                                <span className="text-muted-foreground mt-1 text-xs">
                                                    OFX, QFX, CSV, PDF, XLS ou
                                                    XLSX · até 10 MB
                                                </span>
                                            </label>
                                            <Input
                                                id="file"
                                                name="file"
                                                type="file"
                                                accept=".ofx,.qfx,.csv,.pdf,.xls,.xlsx,application/x-ofx,text/csv,application/pdf,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                                className="sr-only"
                                                required
                                                onChange={onFileChange}
                                            />
                                            {extension !== '' && (
                                                <p className="text-muted-foreground text-xs">
                                                    Formato identificado:{' '}
                                                    <span className="text-foreground font-medium">
                                                        {formatLabel(
                                                            extension,
                                                        )}
                                                    </span>
                                                </p>
                                            )}
                                            <InputError message={errors.file} />
                                        </div>

                                        {needsKindChoice && (
                                            <div className="grid gap-2">
                                                <Label htmlFor="kind">
                                                    O que este arquivo é?
                                                </Label>
                                                <Select
                                                    value={kind}
                                                    onValueChange={(value) =>
                                                        setKind(
                                                            value as UnifiedImportKind,
                                                        )
                                                    }
                                                    required
                                                >
                                                    <SelectTrigger
                                                        id="kind"
                                                        className="w-full"
                                                    >
                                                        <SelectValue placeholder="Extrato ou fatura" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="statement">
                                                            Extrato bancário
                                                        </SelectItem>
                                                        <SelectItem value="invoice">
                                                            Fatura de cartão
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}

                                        {needsPdfLayout && (
                                            <div className="grid gap-2">
                                                <Label htmlFor="pdf_layout">
                                                    Layout do PDF
                                                </Label>
                                                <Select
                                                    name="pdf_layout"
                                                    value={pdfLayout}
                                                    onValueChange={setPdfLayout}
                                                    required
                                                >
                                                    <SelectTrigger
                                                        id="pdf_layout"
                                                        className="w-full"
                                                    >
                                                        <SelectValue placeholder="Selecione o banco e o layout" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {pdfLayouts.map(
                                                            (layout) => (
                                                                <SelectItem
                                                                    key={
                                                                        layout.value
                                                                    }
                                                                    value={
                                                                        layout.value
                                                                    }
                                                                >
                                                                    {
                                                                        layout.label
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                <InputError
                                                    message={errors.pdf_layout}
                                                />
                                            </div>
                                        )}

                                        {kind === 'statement' &&
                                            (accountOptions.length === 0 ? (
                                                <div className="flex flex-col items-center gap-4 rounded-lg border border-dashed p-6 text-center">
                                                    <Landmark className="text-muted-foreground size-8" />
                                                    <p className="font-medium">
                                                        Cadastre uma conta
                                                        primeiro
                                                    </p>
                                                    <Button asChild>
                                                        <Link
                                                            href={createAccount()}
                                                        >
                                                            Cadastrar conta
                                                        </Link>
                                                    </Button>
                                                </div>
                                            ) : (
                                                <div className="grid gap-2">
                                                    <Label htmlFor="financial_account_id">
                                                        Conta do extrato
                                                    </Label>
                                                    <Select
                                                        name="financial_account_id"
                                                        defaultValue={String(
                                                            defaultAccount?.id ??
                                                                accountOptions[0]
                                                                    ?.id,
                                                        )}
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
                                                                        {account.institution
                                                                            ? ` · ${account.institution}`
                                                                            : ''}
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
                                            ))}

                                        {kind === 'invoice' &&
                                            (cardOptions.length === 0 ? (
                                                <div className="flex flex-col items-center gap-4 rounded-lg border border-dashed p-6 text-center">
                                                    <CreditCard className="text-muted-foreground size-8" />
                                                    <p className="font-medium">
                                                        Cadastre um cartão
                                                        primeiro
                                                    </p>
                                                    <Button asChild>
                                                        <Link
                                                            href={createCard()}
                                                        >
                                                            Cadastrar cartão
                                                        </Link>
                                                    </Button>
                                                </div>
                                            ) : (
                                                <div className="grid gap-4 md:grid-cols-2">
                                                    <div className="grid gap-2 md:col-span-2">
                                                        <Label htmlFor="credit_card_id">
                                                            Cartão da fatura
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
                                                                            ·
                                                                            final{' '}
                                                                            {
                                                                                card.last_four
                                                                            }
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
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="amount_sign">
                                                            Sinal das compras
                                                        </Label>
                                                        <Select
                                                            name="amount_sign"
                                                            defaultValue="auto"
                                                            required
                                                        >
                                                            <SelectTrigger
                                                                id="amount_sign"
                                                                className="w-full"
                                                            >
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="auto">
                                                                    Detectar
                                                                    automaticamente
                                                                </SelectItem>
                                                                <SelectItem value="positive">
                                                                    Compras
                                                                    positivas
                                                                </SelectItem>
                                                                <SelectItem value="negative">
                                                                    Compras
                                                                    negativas
                                                                </SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                        <InputError
                                                            message={
                                                                errors.amount_sign
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            ))}

                                        <Button
                                            className="w-full sm:w-auto"
                                            disabled={processing || !canSubmit}
                                        >
                                            <Upload />
                                            {processing
                                                ? 'Processando arquivo…'
                                                : 'Importar'}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    <div className="space-y-4">
                        <Alert>
                            <ShieldCheck />
                            <AlertTitle>Fatura vira despesa</AlertTitle>
                            <AlertDescription>
                                <p>
                                    A fatura lança as compras no livro,
                                    classifica a categoria quando o
                                    estabelecimento é reconhecido e concilia
                                    com parcelas já existentes. Pagamento da
                                    fatura não vira despesa. O extrato bancário
                                    continua pendente até a conciliação.
                                </p>
                            </AlertDescription>
                        </Alert>
                        <Alert>
                            <FileUp />
                            <AlertTitle>Sem pergunta extra</AlertTitle>
                            <AlertDescription>
                                <p>
                                    OFX e CSV já dizem o formato. No PDF,
                                    escolha o layout Banrisul de conta corrente
                                    — outros bancos entram depois.
                                </p>
                            </AlertDescription>
                        </Alert>
                    </div>
                </div>

                {hasRecords && (
                    <ListingToolbar
                        url={listUrl}
                        query={filters}
                        searchPlaceholder="Buscar arquivo ou movimento…"
                        selects={[
                            {
                                key: 'kind',
                                label: 'Origem',
                                value: filters.kind,
                                options: kindOptions,
                                allLabel: 'Todas',
                            },
                            {
                                key: 'status',
                                label: 'Situação',
                                value: filters.status,
                                options: statusOptions,
                                allLabel: 'Todas',
                            },
                        ]}
                    />
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Movimentos importados</CardTitle>
                        <CardDescription>
                            Extratos e faturas recentes, prontos para
                            conciliar.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {entries.length === 0 ? (
                            hasRecords ? (
                                <ListingEmpty />
                            ) : (
                            <div className="flex flex-col items-center gap-3 py-10 text-center">
                                <Upload className="text-muted-foreground size-8" />
                                <p className="font-medium">
                                    Nenhum movimento importado
                                </p>
                            </div>
                            )
                        ) : (
                            <div className="overflow-hidden rounded-lg border">
                                <div className="text-muted-foreground hidden grid-cols-[minmax(0,1.5fr)_minmax(7rem,0.6fr)_minmax(8rem,0.7fr)_minmax(7rem,0.5fr)_minmax(7rem,0.6fr)] gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid">
                                    <SortableColumn
                                        column="description"
                                        label="Movimento"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="date"
                                        label="Data"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="target"
                                        label="Origem"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="status"
                                        label="Situação"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="amount"
                                        label="Valor"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                        align="right"
                                    />
                                </div>
                            <div className="divide-y">
                                {entries.map((entry) => {
                                    const amount = Number(entry.amount);
                                    const isDebit = amount < 0;
                                    const AmountIcon = isDebit
                                        ? ArrowDownCircle
                                        : ArrowUpCircle;

                                    return (
                                        <div
                                            key={entry.id}
                                            className="grid grid-cols-1 gap-2 px-4 py-3 md:grid-cols-[minmax(0,1.5fr)_minmax(7rem,0.6fr)_minmax(8rem,0.7fr)_minmax(7rem,0.5fr)_minmax(7rem,0.6fr)] md:items-center md:gap-3"
                                        >
                                            <div className="flex min-w-0 items-start gap-3">
                                                <AmountIcon
                                                    className={
                                                        isDebit
                                                            ? 'text-destructive mt-0.5 size-5 shrink-0'
                                                            : 'text-positive mt-0.5 size-5 shrink-0'
                                                    }
                                                />
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {entry.description}
                                                    </p>
                                                    {entry.installment_label && (
                                                        <p className="text-muted-foreground mt-1 text-xs">
                                                            {
                                                                entry.installment_label
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                            <p className="text-muted-foreground hidden text-sm md:block md:text-foreground">
                                                {formatDate(entry.occurred_on)}
                                            </p>
                                            <p className="text-muted-foreground hidden truncate text-sm md:block md:text-foreground">
                                                {entry.kind_label} ·{' '}
                                                {entry.target_name}
                                            </p>
                                            <Badge variant="outline" className="w-fit">
                                                {entry.is_reconciled
                                                    ? 'Conciliado'
                                                    : 'Pendente'}
                                            </Badge>
                                            <p
                                                className={
                                                    isDebit
                                                        ? 'text-destructive text-right font-semibold tabular-nums'
                                                        : 'text-positive text-right font-semibold tabular-nums'
                                                }
                                            >
                                                {currency.format(amount)}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <History className="size-5" />
                            Histórico de arquivos
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {imports.length === 0 ? (
                            <p className="text-muted-foreground py-6 text-center text-sm">
                                Nenhum arquivo processado até agora.
                            </p>
                        ) : (
                            <div className="overflow-hidden rounded-lg border">
                                <div className="text-muted-foreground hidden grid-cols-[minmax(0,1.6fr)_minmax(7rem,0.6fr)_minmax(7rem,0.5fr)_minmax(8rem,0.7fr)] gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid">
                                    <SortableColumn
                                        column="filename"
                                        label="Arquivo"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="kind"
                                        label="Tipo"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="status"
                                        label="Situação"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <span className="text-right">Resumo</span>
                                </div>
                            <div className="divide-y">
                                {imports.map((item) => (
                                    <div
                                        key={`${item.kind}-${item.id}`}
                                        className="grid grid-cols-1 gap-2 px-4 py-3 md:grid-cols-[minmax(0,1.6fr)_minmax(7rem,0.6fr)_minmax(7rem,0.5fr)_minmax(8rem,0.7fr)] md:items-center md:gap-3"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {item.source_filename}
                                            </p>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {item.target_name ?? 'Sem destino'}
                                                {item.created_at
                                                    ? ` · ${dateTime.format(new Date(item.created_at))}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <p className="text-muted-foreground hidden text-sm md:block md:text-foreground">
                                            {item.kind_label}
                                        </p>
                                        <Badge
                                            variant={
                                                item.status === 'completed'
                                                    ? 'secondary'
                                                    : item.status === 'failed'
                                                      ? 'destructive'
                                                      : 'outline'
                                            }
                                            className="w-fit"
                                        >
                                            {item.status_label}
                                        </Badge>
                                        <div className="text-right text-sm">
                                            {item.status === 'completed' ? (
                                                <p className="tabular-nums">
                                                    {item.imported_records} novos
                                                    · {item.duplicate_records}{' '}
                                                    duplicados
                                                </p>
                                            ) : item.error_message ? (
                                                <p className="text-destructive text-xs">
                                                    {item.error_message}
                                                </p>
                                            ) : null}
                                        </div>
                                    </div>
                                ))}
                            </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ImportsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Importações',
            href: index(),
        },
    ],
};
