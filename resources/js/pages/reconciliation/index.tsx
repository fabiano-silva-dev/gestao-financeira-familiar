import { Head, Link } from '@inertiajs/react';
import { BadgeCheck, FolderSearch, ListFilter } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { CreateRulePromptDialog } from '@/components/reconciliation/create-rule-prompt-dialog';
import { ReconciliationDetailsSheet } from '@/components/reconciliation/reconciliation-details-sheet';
import { ReconciliationRow } from '@/components/reconciliation/reconciliation-row';
import { ReconciliationScopePicker } from '@/components/reconciliation/reconciliation-scope-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { visitListing } from '@/lib/listing';
import { cn } from '@/lib/utils';
import { index as classificationRulesIndex } from '@/routes/classification-rules';
import { index } from '@/routes/reconciliation';
import type {
    ClassificationRulePrompt,
    ListingQueryState,
    ReconciliationAccountOption,
    ReconciliationCardOption,
    ReconciliationCategoryOption,
    ReconciliationFilters,
    ReconciliationImportOption,
    ReconciliationPendingEntry,
    ReconciliationView,
    ReconciliationViewCounts,
} from '@/types';

type Props = {
    entries: ReconciliationPendingEntry[];
    filters: ReconciliationFilters & ListingQueryState;
    scopeReady: boolean;
    viewCounts: ReconciliationViewCounts;
    accountOptions: ReconciliationAccountOption[];
    cardOptions: ReconciliationCardOption[];
    importOptions: ReconciliationImportOption[];
    counterpartAccountOptions: ReconciliationAccountOption[];
    categoryOptions: ReconciliationCategoryOption[];
    pendingEntriesCount: number;
    unmatchedMovementsCount: number;
    reconciledEntriesCount: number;
};

const views: Array<[ReconciliationView, string]> = [
    ['all', 'Todos'],
    ['pending', 'Pendentes'],
    ['suggestions', 'Sugestões'],
    ['duplicates', 'Possíveis duplicidades'],
    ['uncategorized', 'Sem categoria'],
    ['transfers', 'Transferências'],
    ['reconciled', 'Conciliados'],
];

export default function ReconciliationIndex({
    entries,
    filters,
    scopeReady,
    viewCounts,
    accountOptions,
    cardOptions,
    importOptions,
    counterpartAccountOptions,
    categoryOptions,
    pendingEntriesCount,
    unmatchedMovementsCount,
    reconciledEntriesCount,
}: Props) {
    const [detailsEntry, setDetailsEntry] =
        useState<ReconciliationPendingEntry | null>(null);
    const [rulePrompt, setRulePrompt] =
        useState<ClassificationRulePrompt | null>(null);
    const listUrl = index.url();
    const currentView = filters.view ?? 'all';

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const focus =
            params.get('focus') || window.location.hash.replace('#', '');

        if (focus === '') {
            return;
        }

        const elementId = focus.startsWith('entry-')
            ? focus
            : `entry-${focus}`;
        const timeout = window.setTimeout(() => {
            document.getElementById(elementId)?.scrollIntoView({
                block: 'center',
            });
        }, 50);

        return () => window.clearTimeout(timeout);
    }, [entries]);

    const emptyCopy = (): { title: string; description: string } => {
        if (!scopeReady) {
            return {
                title: 'Escolha o recorte da conciliação',
                description:
                    'Selecione uma conta e um mês, um período ou um arquivo importado para conferir os movimentos.',
            };
        }

        if (pendingEntriesCount === 0 && currentView === 'pending') {
            return {
                title: 'Tudo conciliado por aqui',
                description:
                    'Os movimentos deste recorte já foram conferidos. Veja a aba Todos para revisá-los marcados como conciliados.',
            };
        }

        return {
            title: 'Nenhum movimento neste recorte',
            description:
                'Troque a conta, o mês, o período ou o arquivo, ou use outro filtro do topo.',
        };
    };

    return (
        <>
            <Head title="Conciliação" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p className="text-primary mb-1 text-xs font-semibold tracking-wider uppercase">
                            Conferência
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Conciliação
                        </h1>
                        <p className="text-muted-foreground mt-1 max-w-3xl text-sm">
                            Compare o que veio do banco, do cartão ou da
                            importação com o lançamento interno. A maior parte
                            da conferência acontece nesta lista, sem abrir outra
                            tela.
                        </p>
                    </div>
                    <div className="flex flex-col gap-3 sm:items-end">
                        <div className="text-muted-foreground flex flex-wrap gap-4 text-sm">
                            <span>
                                Pendências{' '}
                                <strong className="text-foreground tabular-nums">
                                    {pendingEntriesCount}
                                </strong>
                            </span>
                            <span>
                                Internos sem vínculo{' '}
                                <strong className="text-foreground tabular-nums">
                                    {unmatchedMovementsCount}
                                </strong>
                            </span>
                            <span>
                                Conciliados{' '}
                                <strong className="text-foreground tabular-nums">
                                    {reconciledEntriesCount}
                                </strong>
                            </span>
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={classificationRulesIndex()}>
                                <ListFilter />
                                Regras
                            </Link>
                        </Button>
                    </div>
                </div>

                <ReconciliationScopePicker
                    url={listUrl}
                    filters={filters}
                    accountOptions={accountOptions}
                    cardOptions={cardOptions}
                    importOptions={importOptions}
                />

                {scopeReady && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            {views.map(([value, label]) => (
                                <Button
                                    key={value}
                                    type="button"
                                    size="sm"
                                    variant={
                                        currentView === value
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        visitListing(listUrl, filters, {
                                            view:
                                                value === 'all' ? null : value,
                                        })
                                    }
                                >
                                    {label}
                                    <span
                                        className={cn(
                                            'tabular-nums',
                                            currentView === value
                                                ? 'text-primary-foreground/80'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {viewCounts[value]}
                                    </span>
                                </Button>
                            ))}
                        </div>

                        <ListingToolbar
                            url={listUrl}
                            query={filters}
                            searchPlaceholder="Buscar descrição original…"
                        />
                    </>
                )}

                {!scopeReady ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
                            <FolderSearch className="text-primary size-9" />
                            <p className="font-medium">{emptyCopy().title}</p>
                            <p className="text-muted-foreground max-w-md text-sm">
                                {emptyCopy().description}
                            </p>
                        </CardContent>
                    </Card>
                ) : entries.length === 0 ? (
                    pendingEntriesCount === 0 && currentView === 'pending' ? (
                        <Card>
                            <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
                                <BadgeCheck className="text-positive size-9" />
                                <p className="font-medium">
                                    {emptyCopy().title}
                                </p>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    {emptyCopy().description}
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <ListingEmpty
                            title={emptyCopy().title}
                            description={emptyCopy().description}
                        />
                    )
                ) : (
                    <Card className="gap-0 overflow-hidden py-0">
                        <div className="text-muted-foreground hidden gap-4 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase lg:grid lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1.2fr)_13rem]">
                            <span>Externo</span>
                            <span>Interno</span>
                            <span>Ações</span>
                        </div>
                        <div className="divide-y">
                            {entries.map((entry) => (
                                <ReconciliationRow
                                    key={`${entry.kind}-${entry.id}`}
                                    entry={entry}
                                    query={filters}
                                    categoryOptions={categoryOptions}
                                    counterpartAccountOptions={
                                        counterpartAccountOptions
                                    }
                                    onOpenDetails={setDetailsEntry}
                                    onAskCreateRule={setRulePrompt}
                                />
                            ))}
                        </div>
                    </Card>
                )}
            </div>

            <ReconciliationDetailsSheet
                entry={detailsEntry}
                onClose={() => setDetailsEntry(null)}
            />
            <CreateRulePromptDialog
                prompt={rulePrompt}
                onClose={() => setRulePrompt(null)}
            />
        </>
    );
}

ReconciliationIndex.layout = {
    breadcrumbs: [
        {
            title: 'Conciliação',
            href: index(),
        },
    ],
};
