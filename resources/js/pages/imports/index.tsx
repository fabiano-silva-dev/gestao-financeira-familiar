import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowDownCircle,
    ArrowUpCircle,
    FileUp,
    History,
    ListChecks,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import { useMemo, useState, type ChangeEvent } from 'react';
import InputError from '@/components/input-error';
import { ImportsNavigation } from '@/components/imports/imports-navigation';
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
import { index, store } from '@/routes/imports';
import { index as reconciliationIndex } from '@/routes/reconciliation';
import type {
    CardStatementCardOption,
    FinancialImportAccountOption,
    UnifiedImportEntry,
    UnifiedImportHistoryItem,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    accountOptions: FinancialImportAccountOption[];
    cardOptions: CardStatementCardOption[];
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

function accountOptionLabel(account: FinancialImportAccountOption) {
    const details = [
        account.institution,
        account.agency ? `Ag. ${account.agency}` : null,
        account.account_number ? `Conta ${account.account_number}` : null,
    ].filter(Boolean);

    return details.length > 0
        ? `${account.name} · ${details.join(' · ')}`
        : account.name;
}

function cardOptionLabel(card: CardStatementCardOption) {
    const details = [
        card.institution,
        `final ${card.last_four}`,
        card.holder_name ? `Titular: ${card.holder_name}` : null,
        card.payment_account_name
            ? `Pagamento: ${card.payment_account_name}`
            : null,
    ].filter(Boolean);

    return `${card.name} · ${details.join(' · ')}`;
}

function detectedDocumentLabel(item: UnifiedImportHistoryItem) {
    const detection = item.autodetection;

    if (!detection) {
        return null;
    }

    const identifier =
        detection.identifier_value && detection.identifier_type
            ? detection.identifier_type === 'card_last_four'
                ? `final ${detection.identifier_value}`
                : `identificador ${detection.identifier_value}`
            : null;
    const holder = detection.holder_name
        ? `titular ${detection.holder_name}`
        : null;
    const reference = detection.reference_month
        ? `referência ${detection.reference_month}`
        : null;

    return [holder, identifier, reference].filter(Boolean).join(' · ') || null;
}

function importReference(item: UnifiedImportHistoryItem) {
    if (item.reference_month) {
        const [year, month] = item.reference_month.split('-');

        return month + '/' + year;
    }

    if (item.statement_start_on && item.statement_end_on) {
        return formatDate(item.statement_start_on) + ' a ' + formatDate(item.statement_end_on);
    }

    return '—';
}

function ImportHistorySummary({ item }: { item: UnifiedImportHistoryItem }) {
    if (item.status === 'completed' && item.processing_summary) {
        return (
            <div className="space-y-0.5 tabular-nums">
                <p>
                    {item.processing_summary.automatically_reconciled} conciliados ·{' '}
                    {item.processing_summary.remaining_exceptions} pendências
                </p>
                <p className="text-muted-foreground text-xs">
                    {item.imported_records} novos · {item.duplicate_records} duplicados ·{' '}
                    {item.processing_summary.categorized_automatically} categorizados automaticamente
                </p>
            </div>
        );
    }

    if (item.status === 'completed') {
        return (
            <p className="tabular-nums">
                {item.imported_records} novos · {item.duplicate_records} duplicados
            </p>
        );
    }

    return (
        <p className="text-muted-foreground text-xs">
            {item.error_message ?? 'Aguardando processamento ou confirmação.'}
        </p>
    );
}

function PendingImportResolver({
    item,
    accountOptions,
    cardOptions,
    defaultReferenceMonth,
}: {
    item: UnifiedImportHistoryItem;
    accountOptions: FinancialImportAccountOption[];
    cardOptions: CardStatementCardOption[];
    defaultReferenceMonth: string;
}) {
    const detectedType = item.autodetection?.document_type;
    const initialType =
        detectedType === 'bank_statement' ||
        detectedType === 'payment_account_statement'
            ? 'bank_statement'
            : detectedType === 'credit_card_statement'
              ? 'credit_card_statement'
              : '';
    const [documentType, setDocumentType] = useState(initialType);
    const needsTypeChoice = initialType === '';
    const isInvoice = documentType === 'credit_card_statement';
    const parserMissing = item.missing_fields.includes('parser');
    const confidence = Math.round((item.autodetection?.confidence ?? 0) * 100);
    const institution = item.autodetection?.institution
        ? item.autodetection.institution.replaceAll('_', ' ')
        : 'não identificada';
    const detectedReference = item.autodetection?.reference_month;
    const detectedDetails = detectedDocumentLabel(item);

    return (
        <div className="rounded-lg border p-4">
            <div className="mb-4 flex flex-col gap-1">
                <p className="font-medium">{item.source_filename}</p>
                <p className="text-muted-foreground text-sm">
                    Instituição: {institution} · confiança {confidence}%
                </p>
                {detectedDetails && (
                    <p className="text-muted-foreground text-xs">
                        Identificado no arquivo: {detectedDetails}
                    </p>
                )}
            </div>

            {parserMissing && !needsTypeChoice && (
                <Alert className="mb-4">
                    <ShieldCheck />
                    <AlertTitle>Layout preservado para revisão</AlertTitle>
                    <AlertDescription>
                        O tipo foi identificado, mas ainda não existe parser
                        determinístico seguro para este layout.
                    </AlertDescription>
                </Alert>
            )}

            <Form
                action={`/importacoes/${item.id}/resolver`}
                method="post"
                options={{ preserveScroll: true }}
                className="grid gap-4 md:grid-cols-2"
            >
                {({ processing, errors }) => (
                    <>
                        {needsTypeChoice ? (
                            <div className="grid gap-2 md:col-span-2">
                                <Label>Tipo do documento</Label>
                                <Select
                                    value={documentType}
                                    onValueChange={setDocumentType}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Selecione o tipo" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="bank_statement">
                                            Extrato bancário
                                        </SelectItem>
                                        <SelectItem value="credit_card_statement">
                                            Fatura de cartão
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.document_type} />
                            </div>
                        ) : (
                            <input
                                type="hidden"
                                name="document_type"
                                value={documentType}
                            />
                        )}

                        {needsTypeChoice && documentType !== '' && (
                            <input
                                type="hidden"
                                name="document_type"
                                value={documentType}
                            />
                        )}

                        {documentType !== '' && !isInvoice && (
                            <div className="grid gap-2 md:col-span-2">
                                <Label>Conta deste extrato</Label>
                                <Select name="financial_account_id" required>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Selecione a conta" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accountOptions.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {accountOptionLabel(account)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.financial_account_id} />
                            </div>
                        )}

                        {isInvoice && (
                            <>
                                <div className="grid gap-2">
                                    <Label>Cartão desta fatura</Label>
                                    <Select name="credit_card_id" required>
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Selecione o cartão" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {cardOptions.map((card) => (
                                                <SelectItem
                                                    key={card.id}
                                                    value={String(card.id)}
                                                >
                                                    {cardOptionLabel(card)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.credit_card_id} />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Mês de vencimento</Label>
                                    <Input
                                        name="reference_month"
                                        type="month"
                                        defaultValue={
                                            detectedReference ??
                                            defaultReferenceMonth
                                        }
                                        required
                                    />
                                    <InputError message={errors.reference_month} />
                                </div>
                            </>
                        )}

                        <div className="md:col-span-2">
                            <Button
                                disabled={
                                    processing ||
                                    documentType === '' ||
                                    parserMissing
                                }
                            >
                                <ShieldCheck />
                                {processing
                                    ? 'Continuando processamento…'
                                    : 'Confirmar e processar'}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}

export default function ImportsIndex({
    accountOptions,
    cardOptions,
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
    const [fileNames, setFileNames] = useState<string[]>([]);
    const canSubmit = fileNames.length > 0;
    const pendingImports = useMemo(
        () => imports.filter((item) => item.status === 'needs_confirmation'),
        [imports],
    );

    const onFileChange = (event: ChangeEvent<HTMLInputElement>) => {
        setFileNames(
            Array.from(event.target.files ?? []).map((file) => file.name),
        );
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
                            Arraste os arquivos. O sistema identifica formato,
                            instituição e tipo do documento, resolve a conta ou
                            cartão e processa automaticamente o que for seguro.
                        </p>
                    </div>

                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <div className="rounded-lg border px-4 py-3">
                            <p className="text-muted-foreground text-xs uppercase">
                                Exceções pendentes
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

                <ImportsNavigation active="upload" />

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
                                onSuccess={() => setFileNames([])}
                                className="space-y-6"
                            >
                                {({ processing, errors }) => (
                                    <>
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
                                                    {fileNames.length > 0
                                                        ? `${fileNames.length} arquivo(s) selecionado(s)`
                                                        : 'Solte os arquivos ou clique para escolher'}
                                                </span>
                                                <span className="text-muted-foreground mt-1 text-xs">
                                                    OFX, QFX, CSV, PDF, XLS ou
                                                    XLSX · até 10 MB
                                                </span>
                                            </label>
                                            <Input
                                                id="file"
                                                name="files[]"
                                                type="file"
                                                multiple
                                                accept=".ofx,.qfx,.csv,.pdf,.xls,.xlsx,application/x-ofx,text/csv,application/pdf,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                                className="sr-only"
                                                required
                                                onChange={onFileChange}
                                            />
                                            {fileNames.length > 0 && (
                                                <p className="text-muted-foreground text-xs">
                                                    {fileNames.join(' · ')}
                                                </p>
                                            )}
                                            <InputError message={errors.files} />
                                            <InputError message={errors['files.0']} />
                                        </div>

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
                                    também é processado e conciliado automaticamente quando a correspondência for segura.
                                </p>
                            </AlertDescription>
                        </Alert>
                        <Alert>
                            <FileUp />
                            <AlertTitle>Sem pergunta extra</AlertTitle>
                            <AlertDescription>
                                <p>
                                    OFX, CSV, planilhas e PDFs passam primeiro
                                    pela autodetecção. Quando houver ambiguidade,
                                    você confirma somente a informação que faltou.
                                </p>
                            </AlertDescription>
                        </Alert>
                    </div>
                </div>

                {pendingImports.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Caixa de Entrada Financeira · documentos</CardTitle>
                            <CardDescription>
                                O arquivo já foi inspecionado. Complete somente
                                o vínculo que não pôde ser determinado com segurança.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {pendingImports.map((item) => (
                                <PendingImportResolver
                                    key={item.id}
                                    item={item}
                                    accountOptions={accountOptions}
                                    cardOptions={cardOptions}
                                    defaultReferenceMonth={defaultReferenceMonth}
                                />
                            ))}
                        </CardContent>
                    </Card>
                )}

                {hasRecords && (
                    <ListingToolbar
                        url={listUrl}
                        query={filters}
                        searchPlaceholder="Buscar arquivo, conta, cartão ou instituição…"
                        selects={[
                            {
                                key: 'kind',
                                label: 'Tipo',
                                value: filters.kind,
                                options: kindOptions,
                                allLabel: 'Todos',
                            },
                            {
                                key: 'status',
                                label: 'Situação',
                                value: filters.status,
                                options: statusOptions,
                                allLabel: 'Todas',
                            },
                            {
                                key: 'account',
                                label: 'Conta',
                                value: filters.account,
                                options: accountOptions.map((account) => ({
                                    value: String(account.id),
                                    label: account.name,
                                })),
                                allLabel: 'Todas',
                            },
                            {
                                key: 'card',
                                label: 'Cartão',
                                value: filters.card,
                                options: cardOptions.map((card) => ({
                                    value: String(card.id),
                                    label: card.name,
                                })),
                                allLabel: 'Todos',
                            },
                        ]}
                        dates={[
                            {
                                key: 'period',
                                label: 'Período',
                                value: filters.period,
                                type: 'month',
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

                <Card id="historico">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <History className="size-5" />
                            Histórico de arquivos
                        </CardTitle>
                        <CardDescription>
                            Confira origem, instituição, referência e resultado. Clique nos cabeçalhos para ordenar.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {imports.length === 0 ? (
                            <p className="text-muted-foreground py-6 text-center text-sm">
                                Nenhum arquivo corresponde aos filtros selecionados.
                            </p>
                        ) : (
                            <>
                                <div className="space-y-3 md:hidden">
                                    {imports.map((item) => (
                                        <div key={item.id} className="rounded-lg border p-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">{item.source_filename}</p>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {item.target_name ?? 'Origem a confirmar'}
                                                    </p>
                                                </div>
                                                <Badge
                                                    variant={
                                                        item.status === 'completed'
                                                            ? 'secondary'
                                                            : item.status === 'failed'
                                                              ? 'destructive'
                                                              : 'outline'
                                                    }
                                                >
                                                    {item.status_label}
                                                </Badge>
                                            </div>
                                            <div className="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                                                <p>{item.institution ?? 'Instituição não informada'}</p>
                                                <p>{item.kind_label}</p>
                                                <p>Referência: {importReference(item)}</p>
                                                <p>
                                                    Importado:{' '}
                                                    {item.imported_at || item.created_at
                                                        ? dateTime.format(new Date(item.imported_at ?? item.created_at ?? ''))
                                                        : '—'}
                                                </p>
                                            </div>
                                            <div className="mt-3 text-sm">
                                                <ImportHistorySummary item={item} />
                                            </div>
                                            <details className="mt-3 rounded-md bg-muted/40 px-3 py-2">
                                                <summary className="cursor-pointer text-xs font-medium">
                                                    Ver detalhes
                                                </summary>
                                                <div className="text-muted-foreground mt-2 space-y-1 text-xs">
                                                    <p>
                                                        Período do documento: {importReference(item)}
                                                    </p>
                                                    <p>
                                                        {item.total_records} registros · {item.imported_records} importados ·{' '}
                                                        {item.duplicate_records} duplicados
                                                    </p>
                                                    {item.status === 'completed' && item.kind !== 'document' && (
                                                        <div className="mt-2 flex flex-wrap gap-2">
                                                            <Button size="sm" variant="outline" asChild>
                                                                <Link href={'/conciliacao?import=' + item.id}>
                                                                    <ListChecks />
                                                                    Abrir conciliação
                                                                </Link>
                                                            </Button>
                                                            <Form
                                                                action={'/conciliacao/importacoes/' + item.id + '/reprocessar'}
                                                                method="post"
                                                                options={{ preserveScroll: true }}
                                                            >
                                                                {({ processing }) => (
                                                                    <Button size="sm" variant="outline" disabled={processing}>
                                                                        <History />
                                                                        Reprocessar
                                                                    </Button>
                                                                )}
                                                            </Form>
                                                        </div>
                                                    )}
                                                </div>
                                            </details>
                                        </div>
                                    ))}
                                </div>

                                <div className="hidden overflow-x-auto rounded-lg border md:block">
                                    <div className="min-w-[1180px]">
                                        <div className="text-muted-foreground grid grid-cols-[minmax(14rem,1.5fr)_minmax(12rem,1.1fr)_minmax(9rem,0.8fr)_minmax(7rem,0.6fr)_minmax(9rem,0.8fr)_minmax(9rem,0.8fr)_minmax(10rem,0.9fr)_minmax(14rem,1.2fr)] gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase">
                                            <SortableColumn
                                                column="filename"
                                                label="Arquivo"
                                                sort={filters.sort}
                                                direction={filters.direction}
                                                onSort={onSort}
                                            />
                                            <SortableColumn
                                                column="target"
                                                label="Conta / cartão"
                                                sort={filters.sort}
                                                direction={filters.direction}
                                                onSort={onSort}
                                            />
                                            <SortableColumn
                                                column="institution"
                                                label="Instituição"
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
                                                column="reference"
                                                label="Referência"
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
                                                column="date"
                                                label="Importado em"
                                                sort={filters.sort}
                                                direction={filters.direction}
                                                onSort={onSort}
                                            />
                                            <SortableColumn
                                                column="summary"
                                                label="Resumo"
                                                sort={filters.sort}
                                                direction={filters.direction}
                                                onSort={onSort}
                                            />
                                        </div>
                                        {imports.map((item) => (
                                            <div
                                                key={item.id}
                                                className="grid grid-cols-[minmax(14rem,1.5fr)_minmax(12rem,1.1fr)_minmax(9rem,0.8fr)_minmax(7rem,0.6fr)_minmax(9rem,0.8fr)_minmax(9rem,0.8fr)_minmax(10rem,0.9fr)_minmax(14rem,1.2fr)] gap-3 border-b px-4 py-3 text-sm last:border-b-0"
                                            >
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">{item.source_filename}</p>
                                                    <details className="mt-1">
                                                        <summary className="text-primary cursor-pointer text-xs">
                                                            Ver detalhes
                                                        </summary>
                                                        <div className="text-muted-foreground mt-2 space-y-1 text-xs">
                                                            <p>
                                                                {item.total_records} registros · {item.imported_records} importados ·{' '}
                                                                {item.duplicate_records} duplicados
                                                            </p>
                                                            {item.status === 'completed' && item.kind !== 'document' && (
                                                                <div className="mt-2 flex flex-wrap gap-2">
                                                                    <Button size="sm" variant="outline" asChild>
                                                                        <Link href={'/conciliacao?import=' + item.id}>
                                                                            <ListChecks />
                                                                            Abrir conciliação
                                                                        </Link>
                                                                    </Button>
                                                                    <Form
                                                                        action={'/conciliacao/importacoes/' + item.id + '/reprocessar'}
                                                                        method="post"
                                                                        options={{ preserveScroll: true }}
                                                                    >
                                                                        {({ processing }) => (
                                                                            <Button size="sm" variant="outline" disabled={processing}>
                                                                                <History />
                                                                                Reprocessar
                                                                            </Button>
                                                                        )}
                                                                    </Form>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </details>
                                                </div>
                                                <p className="min-w-0 truncate">{item.target_name ?? 'A confirmar'}</p>
                                                <p className="min-w-0 truncate">{item.institution ?? '—'}</p>
                                                <p>{item.kind_label}</p>
                                                <p className="tabular-nums">{importReference(item)}</p>
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
                                                <p className="tabular-nums">
                                                    {item.imported_at || item.created_at
                                                        ? dateTime.format(new Date(item.imported_at ?? item.created_at ?? ''))
                                                        : '—'}
                                                </p>
                                                <ImportHistorySummary item={item} />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </>
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
