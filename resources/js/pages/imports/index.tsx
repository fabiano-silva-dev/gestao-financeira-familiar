import { Form, Head, Link } from '@inertiajs/react';
import {
    FileUp,
    History,
    ListChecks,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import { useMemo, useState, type ChangeEvent } from 'react';
import InputError from '@/components/input-error';
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
import { sortListing } from '@/lib/listing';
import { index, store } from '@/routes/imports';
import { index as reconciliationIndex } from '@/routes/reconciliation';
import { index as transactionsIndex } from '@/routes/transactions';
import type {
    CardStatementCardOption,
    FinancialImportAccountOption,
    UnifiedImportHistoryItem,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    accountOptions: FinancialImportAccountOption[];
    cardOptions: CardStatementCardOption[];
    imports: UnifiedImportHistoryItem[];
    pendingEntriesCount: number;
    defaultReferenceMonth: string;
    filters: ListingQueryState;
    hasRecords: boolean;
    kindOptions: ListingFilterOption[];
    statusOptions: ListingFilterOption[];
};

const historyGridClass =
    'md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_7.25rem_7.25rem_minmax(7rem,0.55fr)_11.5rem_minmax(0,1.15fr)_10.75rem]';

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

function formatDate(value: string | null) {
    return value ? date.format(new Date(`${value}T00:00:00Z`)) : '—';
}

function periodLabel(item: UnifiedImportHistoryItem) {
    if (!item.statement_start_on && !item.statement_end_on) {
        return null;
    }

    return `${formatDate(item.statement_start_on)} a ${formatDate(item.statement_end_on)}`;
}

function reconciliationHref(item: UnifiedImportHistoryItem) {
    const query: Record<string, string> = {
        import: String(item.id),
        kind: item.kind === 'invoice' ? 'invoice' : 'statement',
    };

    if (item.kind === 'invoice' && item.credit_card_id) {
        query.card = String(item.credit_card_id);
    }

    if (item.kind !== 'invoice' && item.financial_account_id) {
        query.account = String(item.financial_account_id);
    }

    if (item.reference_month) {
        query.period = item.reference_month;
    }

    return reconciliationIndex({ query });
}

function entriesHref(item: UnifiedImportHistoryItem) {
    return transactionsIndex({
        query: { import: String(item.id) },
    });
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

function formatInstitution(value: string | null | undefined) {
    if (!value) {
        return 'não identificada';
    }

    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatReferenceMonth(value: string) {
    const match = /^(\d{4})-(\d{2})$/.exec(value);

    return match ? `${match[2]}/${match[1]}` : value;
}

function detectedFacts(item: UnifiedImportHistoryItem) {
    const detection = item.autodetection;

    if (!detection) {
        return [];
    }

    const agency =
        typeof detection.metadata?.agency === 'string'
            ? detection.metadata.agency
            : null;
    const facts = [
        detection.holder_name
            ? { label: 'Titular', value: detection.holder_name }
            : null,
        agency ? { label: 'Agência', value: agency } : null,
        detection.identifier_type === 'account_number' &&
        detection.identifier_value
            ? { label: 'Conta', value: detection.identifier_value }
            : null,
        detection.identifier_type === 'card_last_four' &&
        detection.identifier_value
            ? { label: 'Cartão', value: `final ${detection.identifier_value}` }
            : null,
        detection.reference_month
            ? {
                  label: 'Período',
                  value: formatReferenceMonth(detection.reference_month),
              }
            : null,
    ];

    return facts.filter(
        (fact): fact is { label: string; value: string } => fact !== null,
    );
}

function suggestedCardId(
    item: UnifiedImportHistoryItem,
    cards: CardStatementCardOption[],
) {
    const detection = item.autodetection;

    if (!detection || detection.document_type !== 'credit_card_statement') {
        return undefined;
    }

    if (
        detection.identifier_type === 'card_last_four' &&
        detection.identifier_value
    ) {
        const matches = cards.filter(
            (card) => card.last_four === detection.identifier_value,
        );

        if (matches.length === 1) {
            return String(matches[0].id);
        }
    }

    return undefined;
}

function suggestedAccountId(
    item: UnifiedImportHistoryItem,
    accounts: FinancialImportAccountOption[],
) {
    const detection = item.autodetection;

    if (
        !detection ||
        detection.document_type === 'credit_card_statement' ||
        detection.document_type === 'proof'
    ) {
        return undefined;
    }

    const identifier = detection.identifier_value?.replace(/\D/g, '');

    if (identifier) {
        const matches = accounts.filter(
            (account) => account.account_number?.replace(/\D/g, '') === identifier,
        );

        if (matches.length === 1) {
            return String(matches[0].id);
        }
    }

    if (detection.institution) {
        const institution = detection.institution.replaceAll('_', ' ');
        const matches = accounts.filter((account) => {
            const stored = `${account.institution ?? ''} ${account.name}`.toLowerCase();

            return stored.includes(institution.toLowerCase());
        });

        if (matches.length === 1) {
            return String(matches[0].id);
        }
    }

    return undefined;
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
    const [accountId, setAccountId] = useState(
        () => suggestedAccountId(item, accountOptions) ?? '',
    );
    const [cardId, setCardId] = useState(
        () => suggestedCardId(item, cardOptions) ?? '',
    );
    const isInvoice = documentType === 'credit_card_statement';
    const parserMissing =
        item.missing_fields.includes('parser') &&
        (documentType === '' || documentType === initialType);
    const confidence = Math.round((item.autodetection?.confidence ?? 0) * 100);
    const institution = formatInstitution(item.autodetection?.institution);
    const detectedReference = item.autodetection?.reference_month;
    const facts = detectedFacts(item);

    return (
        <div className="rounded-lg border p-4">
            <div className="mb-4 flex flex-col gap-1">
                <p className="font-medium">{item.source_filename}</p>
                <p className="text-muted-foreground text-sm">
                    Instituição: {institution} · confiança {confidence}%
                </p>
            </div>

            {facts.length > 0 && (
                <dl className="mb-4 grid gap-3 text-sm sm:grid-cols-2">
                    {facts.map((fact) => (
                        <div key={fact.label}>
                            <dt className="text-muted-foreground text-xs">
                                {fact.label}
                            </dt>
                            <dd className="font-medium">{fact.value}</dd>
                        </div>
                    ))}
                </dl>
            )}

            {parserMissing && (
                <Alert className="mb-4">
                    <ShieldCheck />
                    <AlertTitle>Layout preservado para revisão</AlertTitle>
                    <AlertDescription>
                        O tipo foi identificado, mas ainda não existe parser
                        determinístico seguro para este layout. Se a leitura
                        estiver errada, troque o tipo abaixo.
                    </AlertDescription>
                </Alert>
            )}

            {!parserMissing && initialType !== '' && (
                <Alert className="mb-4">
                    <ShieldCheck />
                    <AlertTitle>Layout reconhecido</AlertTitle>
                    <AlertDescription>
                        Os dados do arquivo foram lidos. O tipo, a conta e o
                        cartão abaixo são a sugestão — troque o que não
                        estiver certo antes de importar.
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
                        <div className="grid gap-2 md:col-span-2">
                            <Label>Tipo do documento</Label>
                            <Select
                                name="document_type"
                                value={documentType}
                                onValueChange={setDocumentType}
                                required
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

                        {documentType !== '' && !isInvoice && (
                            <div className="grid gap-2 md:col-span-2">
                                <Label>Conta deste extrato</Label>
                                <Select
                                    name="financial_account_id"
                                    value={accountId}
                                    onValueChange={setAccountId}
                                    required
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
                                    <Select
                                        name="credit_card_id"
                                        value={cardId}
                                        onValueChange={setCardId}
                                        required
                                    >
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

function DestinationDialog({
    item,
    accountOptions,
    cardOptions,
    defaultReferenceMonth,
    onClose,
}: {
    item: UnifiedImportHistoryItem;
    accountOptions: FinancialImportAccountOption[];
    cardOptions: CardStatementCardOption[];
    defaultReferenceMonth: string;
    onClose: () => void;
}) {
    const initialType =
        item.kind === 'invoice' ? 'credit_card_statement' : 'bank_statement';
    const [documentType, setDocumentType] = useState(initialType);
    const [accountId, setAccountId] = useState(
        item.financial_account_id ? String(item.financial_account_id) : '',
    );
    const [cardId, setCardId] = useState(
        item.credit_card_id ? String(item.credit_card_id) : '',
    );
    const isInvoice = documentType === 'credit_card_statement';
    const typeChanged = (item.kind === 'invoice') !== isInvoice;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Alterar destino</DialogTitle>
                    <DialogDescription>
                        As linhas deste arquivo passam para a conta ou o cartão
                        escolhido. O que o arquivo criou acompanha a mudança, e
                        vínculos com lançamentos que já existiam no destino
                        anterior são desfeitos. Se o tipo mudar, o arquivo é
                        lido de novo e a importação anterior sai do lugar
                        antigo.
                    </DialogDescription>
                </DialogHeader>
                <p className="truncate text-sm font-medium">
                    {item.source_filename}
                </p>
                <Form
                    action={`/importacoes/${item.id}/destino`}
                    method="post"
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label>Tipo</Label>
                                <Select
                                    name="document_type"
                                    value={documentType}
                                    onValueChange={setDocumentType}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
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
                            {!isInvoice && (
                                <div className="grid gap-2">
                                    <Label>Conta</Label>
                                    <Select
                                        name="financial_account_id"
                                        value={accountId}
                                        onValueChange={setAccountId}
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
                                                    {accountOptionLabel(account)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.financial_account_id}
                                    />
                                </div>
                            )}
                            {isInvoice && (
                                <>
                                    <div className="grid gap-2">
                                        <Label>Cartão</Label>
                                        <Select
                                            name="credit_card_id"
                                            value={cardId}
                                            onValueChange={setCardId}
                                        >
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
                                        <InputError
                                            message={errors.credit_card_id}
                                        />
                                    </div>
                                    {typeChanged ? (
                                        <div className="grid gap-2">
                                            <Label>Mês de vencimento</Label>
                                            <Input
                                                name="reference_month"
                                                type="month"
                                                defaultValue={
                                                    item.reference_month ??
                                                    defaultReferenceMonth
                                                }
                                                required
                                            />
                                            <InputError
                                                message={errors.reference_month}
                                            />
                                        </div>
                                    ) : (
                                        <input
                                            type="hidden"
                                            name="reference_month"
                                            value={
                                                item.reference_month ??
                                                defaultReferenceMonth
                                            }
                                        />
                                    )}
                                </>
                            )}
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                    disabled={processing}
                                >
                                    Cancelar
                                </Button>
                                <Button
                                    disabled={
                                        processing ||
                                        (!isInvoice && accountId === '') ||
                                        (isInvoice && cardId === '')
                                    }
                                >
                                    {processing
                                        ? 'Atualizando…'
                                        : 'Salvar destino'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ImportSummary({ item }: { item: UnifiedImportHistoryItem }) {
    if (item.status === 'completed' && item.processing_summary) {
        const summary = item.processing_summary;

        return (
            <div className="space-y-0.5 text-sm tabular-nums">
                <p>
                    {summary.automatically_reconciled} conciliados ·{' '}
                    {summary.new_transactions_created} lançamentos
                </p>
                <p className="text-muted-foreground text-xs">
                    {summary.transfers_identified} transferências ·{' '}
                    {summary.invoice_payments_identified} pgto. fatura ·{' '}
                    {summary.refunds_identified} reembolsos ·{' '}
                    {summary.categorized_automatically} categorizados
                </p>
                <p className="text-muted-foreground text-xs">
                    {summary.pending_categorization} sem categoria ·{' '}
                    {summary.pending_confirmation} confirmações ·{' '}
                    {summary.duplicates_ignored} duplicados
                </p>
            </div>
        );
    }

    if (item.status === 'completed') {
        return (
            <p className="text-sm tabular-nums">
                {item.imported_records} novos · {item.duplicate_records}{' '}
                duplicados
            </p>
        );
    }

    if (item.error_message) {
        return <p className="text-destructive text-xs">{item.error_message}</p>;
    }

    return <p className="text-muted-foreground text-sm">—</p>;
}

export default function ImportsIndex({
    accountOptions,
    cardOptions,
    imports,
    pendingEntriesCount,
    defaultReferenceMonth,
    filters,
    hasRecords,
    kindOptions,
    statusOptions,
}: Props) {
    const listUrl = index.url();
    const onSort = (column: string) =>
        sortListing(listUrl, filters, column, 'asc');
    const [fileNames, setFileNames] = useState<string[]>([]);
    const [destination, setDestination] =
        useState<UnifiedImportHistoryItem | null>(null);
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
                        searchPlaceholder="Buscar arquivo…"
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
                        <CardTitle className="flex items-center gap-2">
                            <History className="size-5" />
                            Histórico de arquivos
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {imports.length === 0 ? (
                            <div className="flex flex-col items-center gap-3 py-10 text-center">
                                <Upload className="text-muted-foreground size-8" />
                                <p className="font-medium">
                                    Nenhum arquivo processado até agora.
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <div
                                    className={`text-muted-foreground hidden min-w-[1180px] gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid ${historyGridClass}`}
                                >
                                    <SortableColumn
                                        column="filename"
                                        label="Arquivo"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <span>Conta / cartão</span>
                                    <span>Data inicial</span>
                                    <span>Data final</span>
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
                                    <span>Resumo</span>
                                    <span className="text-right">Ações</span>
                                </div>
                                <div className="divide-y">
                                    {imports.map((item) => (
                                        <div
                                            key={`${item.kind}-${item.id}`}
                                            className={`grid grid-cols-1 gap-2 px-4 py-3 md:min-w-[1180px] md:items-start md:gap-3 ${historyGridClass}`}
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {item.source_filename}
                                                </p>
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    {item.created_at
                                                        ? dateTime.format(
                                                              new Date(
                                                                  item.created_at,
                                                              ),
                                                          )
                                                        : '—'}
                                                </p>
                                            </div>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm">
                                                    {item.target_name ??
                                                        'Sem destino'}
                                                </p>
                                                {periodLabel(item) && (
                                                    <p className="text-muted-foreground mt-1 text-xs md:hidden">
                                                        {periodLabel(item)}
                                                    </p>
                                                )}
                                            </div>
                                            <p className="hidden text-sm tabular-nums md:block">
                                                {formatDate(
                                                    item.statement_start_on,
                                                )}
                                            </p>
                                            <p className="hidden text-sm tabular-nums md:block">
                                                {formatDate(
                                                    item.statement_end_on,
                                                )}
                                            </p>
                                            <p className="text-muted-foreground hidden min-w-0 text-sm md:block md:text-foreground">
                                                {item.kind_label}
                                            </p>
                                            <div className="min-w-0">
                                                <Badge
                                                    variant={
                                                        item.status ===
                                                        'completed'
                                                            ? 'secondary'
                                                            : item.status ===
                                                                'failed'
                                                              ? 'destructive'
                                                              : 'outline'
                                                    }
                                                    className="max-w-full whitespace-normal"
                                                >
                                                    {item.status_label}
                                                </Badge>
                                            </div>
                                            <div className="min-w-0">
                                                <ImportSummary item={item} />
                                            </div>
                                            <div className="flex min-w-0 flex-col items-stretch gap-1.5">
                                                {item.can_reassign && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setDestination(
                                                                item,
                                                            )
                                                        }
                                                    >
                                                        Alterar destino
                                                    </Button>
                                                )}
                                                {item.kind !== 'document' &&
                                                    item.status ===
                                                        'completed' && (
                                                        <>
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={reconciliationHref(
                                                                        item,
                                                                    )}
                                                                >
                                                                    Conciliar
                                                                </Link>
                                                            </Button>
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={entriesHref(
                                                                        item,
                                                                    )}
                                                                >
                                                                    Lançamentos
                                                                </Link>
                                                            </Button>
                                                        </>
                                                    )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
                {destination && (
                    <DestinationDialog
                        item={destination}
                        accountOptions={accountOptions}
                        cardOptions={cardOptions}
                        defaultReferenceMonth={defaultReferenceMonth}
                        onClose={() => setDestination(null)}
                    />
                )}
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
