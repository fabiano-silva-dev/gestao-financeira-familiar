import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowDownCircle,
    ArrowUpCircle,
    FileCheck2,
    FileUp,
    History,
    Landmark,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import OfxImportController from '@/actions/App/Http/Controllers/OfxImportController';
import InputError from '@/components/input-error';
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
import { create as createAccount } from '@/routes/accounts';
import { index } from '@/routes/imports/ofx';
import type {
    BankStatementEntry,
    FinancialImportAccountOption,
    FinancialImportHistoryItem,
} from '@/types';

type Props = {
    accountOptions: FinancialImportAccountOption[];
    imports: FinancialImportHistoryItem[];
    entries: BankStatementEntry[];
    pendingEntriesCount: number;
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
    return date.format(new Date(value + 'T00:00:00Z'));
}

function statementPeriod(item: FinancialImportHistoryItem) {
    if (!item.statement_start_on && !item.statement_end_on) {
        return 'Período não informado pelo banco';
    }

    if (item.statement_start_on && item.statement_end_on) {
        return `${formatDate(item.statement_start_on)} a ${formatDate(item.statement_end_on)}`;
    }

    return formatDate(item.statement_start_on ?? item.statement_end_on!);
}

export default function OfxImportPage({
    accountOptions,
    imports,
    entries,
    pendingEntriesCount,
}: Props) {
    const defaultAccount = accountOptions.find((account) => account.is_active);

    return (
        <>
            <Head title="Importar extrato OFX" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Fase 5 · Automação
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Importar extrato OFX
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Traga os movimentos reais do banco com prevenção de
                            duplicidades.
                        </p>
                    </div>

                    <div className="rounded-lg border px-4 py-3">
                        <p className="text-muted-foreground text-xs uppercase">
                            Aguardando conciliação
                        </p>
                        <p className="text-xl font-semibold tabular-nums">
                            {pendingEntriesCount}
                        </p>
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
                                Selecione a conta correspondente antes de enviar
                                o arquivo do banco.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {accountOptions.length === 0 ? (
                                <div className="flex flex-col items-center gap-4 rounded-lg border border-dashed p-8 text-center">
                                    <Landmark className="text-muted-foreground size-8" />
                                    <div>
                                        <p className="font-medium">
                                            Cadastre uma conta primeiro
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            O extrato precisa ser associado à
                                            conta correta do sistema.
                                        </p>
                                    </div>
                                    <Button asChild>
                                        <Link href={createAccount()}>
                                            Cadastrar conta
                                        </Link>
                                    </Button>
                                </div>
                            ) : (
                                <Form
                                    {...OfxImportController.store.form()}
                                    options={{
                                        preserveScroll: true,
                                    }}
                                    resetOnSuccess
                                    className="space-y-6"
                                >
                                    {({ processing, errors }) => (
                                        <>
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
                                                                    {!account.is_active
                                                                        ? ' · inativa'
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

                                            <div className="grid gap-2">
                                                <Label htmlFor="file">
                                                    Arquivo OFX ou QFX
                                                </Label>
                                                <Input
                                                    id="file"
                                                    name="file"
                                                    type="file"
                                                    accept=".ofx,.qfx,application/x-ofx"
                                                    required
                                                />
                                                <p className="text-muted-foreground text-xs">
                                                    Limite de 5 MB. O arquivo é
                                                    armazenado em área privada.
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
                                                    ? 'Processando arquivo…'
                                                    : 'Importar movimentos'}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            )}
                        </CardContent>
                    </Card>

                    <div className="space-y-4">
                        <Alert>
                            <ShieldCheck />
                            <AlertTitle>Importação idempotente</AlertTitle>
                            <AlertDescription>
                                <p>
                                    Reenviar o mesmo arquivo ou movimentos com o
                                    mesmo identificador bancário não cria
                                    cópias.
                                </p>
                            </AlertDescription>
                        </Alert>
                        <Alert>
                            <FileCheck2 />
                            <AlertTitle>Sem despesas automáticas</AlertTitle>
                            <AlertDescription>
                                <p>
                                    O OFX cria registros bancários pendentes.
                                    Ele não cria receitas ou despesas antes da
                                    conciliação.
                                </p>
                            </AlertDescription>
                        </Alert>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Movimentos importados</CardTitle>
                        <CardDescription>
                            Os 50 movimentos bancários mais recentes, prontos
                            para a próxima etapa de conciliação.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {entries.length === 0 ? (
                            <div className="flex flex-col items-center gap-3 py-10 text-center">
                                <Upload className="text-muted-foreground size-8" />
                                <p className="font-medium">
                                    Nenhum movimento importado
                                </p>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Envie o primeiro OFX para iniciar o
                                    histórico bancário.
                                </p>
                            </div>
                        ) : (
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
                                            className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
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
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {entry.account_name}
                                                        {' · '}
                                                        {formatDate(
                                                            entry.occurred_on,
                                                        )}
                                                        {entry.transaction_type
                                                            ? ` · ${entry.transaction_type}`
                                                            : ''}
                                                    </p>
                                                    {entry.memo &&
                                                        entry.memo !==
                                                            entry.description && (
                                                            <p className="text-muted-foreground mt-1 line-clamp-1 text-xs">
                                                                {entry.memo}
                                                            </p>
                                                        )}
                                                </div>
                                            </div>
                                            <div className="flex items-center justify-between gap-3 sm:justify-end">
                                                <Badge variant="outline">
                                                    Pendente
                                                </Badge>
                                                <p
                                                    className={
                                                        isDebit
                                                            ? 'text-destructive font-semibold tabular-nums'
                                                            : 'text-positive font-semibold tabular-nums'
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
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    {item.account_name}
                                                    {' · '}
                                                    {statementPeriod(item)}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={
                                                    item.status === 'completed'
                                                        ? 'secondary'
                                                        : item.status ===
                                                            'failed'
                                                          ? 'destructive'
                                                          : 'outline'
                                                }
                                            >
                                                {item.status_label}
                                            </Badge>
                                        </div>

                                        {item.status === 'completed' ? (
                                            <div className="mt-4 grid grid-cols-3 gap-3 text-sm">
                                                <div>
                                                    <p className="text-muted-foreground text-xs">
                                                        Lidos
                                                    </p>
                                                    <p className="font-semibold tabular-nums">
                                                        {item.total_records}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground text-xs">
                                                        Novos
                                                    </p>
                                                    <p className="font-semibold tabular-nums">
                                                        {item.imported_records}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground text-xs">
                                                        Duplicados
                                                    </p>
                                                    <p className="font-semibold tabular-nums">
                                                        {item.duplicate_records}
                                                    </p>
                                                </div>
                                            </div>
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
                </Card>
            </div>
        </>
    );
}

OfxImportPage.layout = {
    breadcrumbs: [
        {
            title: 'Importar OFX',
            href: index(),
        },
    ],
};
