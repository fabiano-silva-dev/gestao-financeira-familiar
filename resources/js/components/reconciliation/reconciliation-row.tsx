import { Link, router } from '@inertiajs/react';
import {
    ArrowDownCircle,
    ArrowLeftRight,
    ArrowUpCircle,
    Ban,
    Eye,
    Link2,
    ListFilter,
    Plus,
    RotateCcw,
    Sparkles,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import BankReconciliationController from '@/actions/App/Http/Controllers/BankReconciliationController';
import CardStatementReconciliationController from '@/actions/App/Http/Controllers/CardStatementReconciliationController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { classificationRuleCreateQuery } from '@/lib/classification-rule';
import { listingUrl } from '@/lib/listing';
import {
    candidateMatchId,
    formatReconciliationDate,
    reconciliationEntryElementId,
} from '@/lib/reconciliation';
import { cn } from '@/lib/utils';
import { create as createRule } from '@/routes/classification-rules';
import { index as reconciliationIndex } from '@/routes/reconciliation';
import type {
    ClassificationRulePrompt,
    ListingQueryState,
    ReconciliationAccountOption,
    ReconciliationCandidate,
    ReconciliationCategoryOption,
    ReconciliationPendingEntry,
} from '@/types';

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

function isOutflow(entry: ReconciliationPendingEntry): boolean {
    if (entry.kind === 'invoice') {
        return true;
    }

    return Number(entry.amount) < 0;
}

function visitOptions() {
    return {
        preserveScroll: true,
        preserveState: true,
    };
}

type Props = {
    entry: ReconciliationPendingEntry;
    query: ListingQueryState;
    categoryOptions: ReconciliationCategoryOption[];
    counterpartAccountOptions: ReconciliationAccountOption[];
    onOpenDetails: (entry: ReconciliationPendingEntry) => void;
    onAskCreateRule: (prompt: ClassificationRulePrompt) => void;
};

export function ReconciliationRow({
    entry,
    query,
    categoryOptions,
    counterpartAccountOptions,
    onOpenDetails,
    onAskCreateRule,
}: Props) {
    const suggestion = entry.candidates.find(
        (candidate) => candidate.is_suggestion,
    );
    const [matchId, setMatchId] = useState(() =>
        suggestion ? candidateMatchId(entry, suggestion) : '',
    );
    const selectedCandidate = entry.candidates.find(
        (candidate) => candidateMatchId(entry, candidate) === matchId,
    );
    const outflow = isOutflow(entry);
    const categoryType = outflow ? 'expense' : 'income';
    const parents = useMemo(
        () =>
            categoryOptions.filter(
                (category) =>
                    category.parent_id === null &&
                    category.type === categoryType,
            ),
        [categoryOptions, categoryType],
    );
    const parentId =
        entry.parent_category_id ??
        selectedCandidate?.related_parent_category_id ??
        entry.matcher_parent_category_id;
    const [selectedParentId, setSelectedParentId] = useState(
        parentId ? String(parentId) : '',
    );
    const children = useMemo(
        () =>
            categoryOptions.filter(
                (category) =>
                    selectedParentId !== '' &&
                    category.parent_id === Number(selectedParentId),
            ),
        [categoryOptions, selectedParentId],
    );
    const subcategoryId =
        entry.subcategory_id ??
        selectedCandidate?.related_subcategory_id ??
        (entry.parent_category_id === Number(selectedParentId)
            ? null
            : entry.matcher_subcategory_id);
    const [selectedSubId, setSelectedSubId] = useState(
        subcategoryId ? String(subcategoryId) : '',
    );
    const displayedPayee =
        entry.payee_name ??
        selectedCandidate?.related_payee_name ??
        entry.related_payee_name ??
        entry.matcher_payee_name ??
        '';
    const [payee, setPayee] = useState(displayedPayee);
    const [showTransfer, setShowTransfer] = useState(
        entry.kind === 'statement' && entry.matcher_action_type === 'transfer',
    );
    const [counterpartId, setCounterpartId] = useState(
        entry.matcher_counterpart_account_id
            ? String(entry.matcher_counterpart_account_id)
            : '',
    );

    useEffect(() => {
        const nextSuggestion = entry.candidates.find(
            (candidate) => candidate.is_suggestion,
        );
        setMatchId(
            nextSuggestion ? candidateMatchId(entry, nextSuggestion) : '',
        );
        setPayee(
            entry.payee_name ??
                nextSuggestion?.related_payee_name ??
                entry.related_payee_name ??
                entry.matcher_payee_name ??
                '',
        );
        const nextParent =
            entry.parent_category_id ??
            nextSuggestion?.related_parent_category_id ??
            entry.matcher_parent_category_id;
        setSelectedParentId(nextParent ? String(nextParent) : '');
        const nextSub =
            entry.subcategory_id ??
            nextSuggestion?.related_subcategory_id ??
            entry.matcher_subcategory_id;
        setSelectedSubId(nextSub ? String(nextSub) : '');
        setShowTransfer(
            entry.kind === 'statement' &&
                entry.matcher_action_type === 'transfer',
        );
        setCounterpartId(
            entry.matcher_counterpart_account_id
                ? String(entry.matcher_counterpart_account_id)
                : '',
        );
    }, [
        entry.id,
        entry.kind,
        entry.payee_name,
        entry.parent_category_id,
        entry.subcategory_id,
        entry.matcher_parent_category_id,
        entry.matcher_subcategory_id,
        entry.related_payee_name,
        entry.matcher_payee_name,
        entry.matcher_action_type,
        entry.matcher_counterpart_account_id,
        entry.candidates,
    ]);

    const Icon = outflow ? ArrowDownCircle : ArrowUpCircle;
    const leafCategoryId =
        selectedSubId !== ''
            ? Number(selectedSubId)
            : selectedParentId !== ''
              ? Number(selectedParentId)
              : null;
    const relatedLabel =
        selectedCandidate?.related_description ??
        selectedCandidate?.description ??
        entry.related_description;
    const relatedType =
        selectedCandidate?.related_type_label ??
        selectedCandidate?.type_label ??
        entry.related_type_label;
    const relatedAccount =
        selectedCandidate?.related_account_name ?? entry.related_account_name;
    const competence =
        selectedCandidate?.related_competence_date ??
        entry.related_competence_date;
    const selectedInvoice =
        selectedCandidate?.is_invoice_payment || entry.is_invoice_payment
            ? {
                  cardName:
                      selectedCandidate?.card_name ?? entry.card_name ?? null,
                  invoiceLabel:
                      selectedCandidate?.invoice_label ??
                      entry.invoice_label ??
                      null,
                  dueDate:
                      selectedCandidate?.invoice_due_date ??
                      entry.invoice_due_date,
                  total:
                      selectedCandidate?.invoice_total_amount ??
                      entry.invoice_total_amount,
                  paid:
                      selectedCandidate?.invoice_paid_amount ??
                      entry.invoice_paid_amount,
                  outstanding:
                      selectedCandidate?.invoice_outstanding_amount ??
                      entry.invoice_outstanding_amount,
                  statusLabel:
                      selectedCandidate?.invoice_status_label ??
                      entry.invoice_status_label,
              }
            : null;
    const showInvoicePayment =
        entry.is_likely_invoice_payment || selectedInvoice !== null;
    const canCreate =
        !entry.is_reconciled &&
        !entry.has_suggestion &&
        !entry.is_likely_invoice_payment &&
        !entry.is_likely_transfer;
    const counterpartOptions = counterpartAccountOptions.filter(
        (account) => account.id !== entry.financial_account_id,
    );

    const classify = (nextPayee: string, nextCategoryId: number | null) => {
        const payload = {
            payee_name: nextPayee === '' ? null : nextPayee,
            category_id: nextCategoryId,
        };

        if (entry.kind === 'statement') {
            router.patch(
                listingUrl(
                    BankReconciliationController.classify.url(entry.id),
                    query,
                ),
                payload,
                visitOptions(),
            );

            return;
        }

        router.patch(
            listingUrl(
                CardStatementReconciliationController.classify.url({
                    invoice: entry.invoice_id ?? 0,
                    entry: entry.id,
                }),
                query,
            ),
            payload,
            visitOptions(),
        );
    };

    const rulePrompt = (): ClassificationRulePrompt => {
        const isTransfer =
            showTransfer ||
            entry.related_is_transfer ||
            entry.matcher_action_type === 'transfer';

        return {
            description: entry.description,
            payee_name: payee,
            action_type: isTransfer
                ? 'transfer'
                : outflow
                  ? 'expense'
                  : 'income',
            category_id: isTransfer ? null : leafCategoryId,
            counterpart_account_id: isTransfer
                ? counterpartId !== ''
                    ? Number(counterpartId)
                    : (entry.matcher_counterpart_account_id ?? null)
                : null,
            return_to: listingUrl(reconciliationIndex.url(), {
                ...query,
                focus: `${entry.kind}-${entry.id}`,
            }),
        };
    };

    const askCreateRuleIfNeeded = () => {
        if (entry.matcher_rule_id != null || showInvoicePayment) {
            return;
        }

        onAskCreateRule(rulePrompt());
    };

    const completeOptions = () => ({
        ...visitOptions(),
        onSuccess: askCreateRuleIfNeeded,
    });

    const conciliate = () => {
        if (entry.kind === 'statement') {
            if (matchId.startsWith('invoice:')) {
                router.post(
                    listingUrl(
                        BankReconciliationController.invoicePayment.url(
                            entry.id,
                        ),
                        query,
                    ),
                    { credit_card_invoice_id: Number(matchId.slice(8)) },
                    completeOptions(),
                );

                return;
            }

            router.post(
                listingUrl(
                    BankReconciliationController.store.url(entry.id),
                    query,
                ),
                { account_movement_id: Number(matchId) },
                completeOptions(),
            );

            return;
        }

        router.post(
            listingUrl(
                CardStatementReconciliationController.store.url({
                    invoice: entry.invoice_id ?? 0,
                    entry: entry.id,
                }),
                query,
            ),
            { transaction_installment_id: Number(matchId) },
            completeOptions(),
        );
    };

    const createEntry = () => {
        if (entry.kind === 'statement') {
            router.post(
                listingUrl(
                    BankReconciliationController.create.url(entry.id),
                    query,
                ),
                {},
                completeOptions(),
            );

            return;
        }

        router.post(
            listingUrl(
                CardStatementReconciliationController.create.url({
                    invoice: entry.invoice_id ?? 0,
                    entry: entry.id,
                }),
                query,
            ),
            {},
            completeOptions(),
        );
    };

    const ignore = () => {
        if (entry.kind === 'statement') {
            router.patch(
                listingUrl(
                    BankReconciliationController.ignore.url(entry.id),
                    query,
                ),
                {},
                visitOptions(),
            );

            return;
        }

        router.patch(
            listingUrl(
                CardStatementReconciliationController.ignore.url({
                    invoice: entry.invoice_id ?? 0,
                    entry: entry.id,
                }),
                query,
            ),
            {},
            visitOptions(),
        );
    };

    const undo = () => {
        if (entry.kind === 'statement') {
            router.delete(
                listingUrl(
                    BankReconciliationController.destroy.url(entry.id),
                    query,
                ),
                visitOptions(),
            );

            return;
        }

        router.delete(
            listingUrl(
                CardStatementReconciliationController.destroy.url({
                    invoice: entry.invoice_id ?? 0,
                    entry: entry.id,
                }),
                query,
            ),
            visitOptions(),
        );
    };

    const markTransfer = () => {
        if (counterpartId === '') {
            return;
        }

        router.post(
            listingUrl(
                BankReconciliationController.transfer.url(entry.id),
                query,
            ),
            { counterpart_account_id: Number(counterpartId) },
            completeOptions(),
        );
    };

    return (
        <article
            id={reconciliationEntryElementId(entry.kind, entry.id)}
            className={cn(
                'scroll-mt-24 grid gap-4 px-4 py-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1.2fr)_auto] lg:items-start',
                entry.is_possible_duplicate && 'bg-warning-muted',
                entry.is_reconciled && 'bg-muted/40',
            )}
        >
            <div className="min-w-0 space-y-2">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                        <Icon
                            className={cn(
                                'mt-0.5 size-4 shrink-0',
                                outflow ? 'text-destructive' : 'text-positive',
                            )}
                        />
                        <div className="min-w-0">
                            <p className="truncate font-medium">
                                {entry.description}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                {formatReconciliationDate(entry.occurred_on)}
                                {entry.installment_label
                                    ? ` · ${entry.installment_label}`
                                    : ''}
                            </p>
                        </div>
                    </div>
                    <p
                        className={cn(
                            'shrink-0 font-semibold tabular-nums',
                            outflow ? 'text-destructive' : 'text-positive',
                        )}
                    >
                        {currency.format(Number(entry.amount))}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                    <Badge variant="outline">{entry.source_format_label}</Badge>
                    <Badge variant="secondary">{entry.source_name}</Badge>
                    {entry.import_id !== null && (
                        <Badge variant="outline">
                            Importação #{entry.import_id}
                        </Badge>
                    )}
                    {entry.is_possible_duplicate && (
                        <Badge variant="destructive">
                            Possível duplicidade
                        </Badge>
                    )}
                    {entry.is_likely_invoice_payment && (
                        <Badge variant="outline">Pagamento de fatura</Badge>
                    )}
                    {entry.is_likely_transfer && (
                        <Badge variant="outline">Transferência</Badge>
                    )}
                    {entry.is_reconciled && (
                        <Badge variant="secondary">Conciliado</Badge>
                    )}
                </div>
                <p className="text-muted-foreground text-xs">
                    {entry.relation_path}
                    {entry.import_filename ? ` · ${entry.import_filename}` : ''}
                </p>
            </div>

            <div className="min-w-0 space-y-2">
                {(entry.has_suggestion ||
                    entry.matcher_category_name ||
                    entry.suggestion_description) &&
                    !entry.is_reconciled && (
                        <p className="text-primary flex items-start gap-1.5 text-xs">
                            <Sparkles className="mt-0.5 size-3 shrink-0" />
                            <span>
                                {entry.suggestion_description
                                    ? entry.suggestion_description
                                    : 'Sugestão de classificação'}
                                {!showInvoicePayment &&
                                entry.matcher_category_name
                                    ? ` · ${entry.matcher_category_name}${
                                          entry.matcher_subcategory_name
                                              ? ` / ${entry.matcher_subcategory_name}`
                                              : ''
                                      }`
                                    : ''}
                                {entry.suggestion_confidence_label
                                    ? ` · ${entry.suggestion_confidence_label}`
                                    : ''}
                                {entry.suggestion_score
                                    ? ` (${entry.suggestion_score}%)`
                                    : ''}
                            </span>
                        </p>
                    )}

                {showInvoicePayment ? (
                    <dl className="bg-muted/50 grid gap-2 rounded-lg border px-3 py-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                Conta bancária
                            </dt>
                            <dd>{entry.account_name}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                Cartão
                            </dt>
                            <dd>
                                {selectedInvoice?.cardName ??
                                    entry.card_name ??
                                    '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                Fatura
                            </dt>
                            <dd>{selectedInvoice?.invoiceLabel ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                Vencimento
                            </dt>
                            <dd>
                                {selectedInvoice?.dueDate
                                    ? formatReconciliationDate(
                                          selectedInvoice.dueDate,
                                      )
                                    : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                Total da fatura
                            </dt>
                            <dd className="tabular-nums">
                                {selectedInvoice?.total
                                    ? currency.format(
                                          Number(selectedInvoice.total),
                                      )
                                    : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                Já pago
                            </dt>
                            <dd className="tabular-nums">
                                {selectedInvoice?.paid
                                    ? currency.format(
                                          Number(selectedInvoice.paid),
                                      )
                                    : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                Saldo em aberto
                            </dt>
                            <dd className="tabular-nums">
                                {selectedInvoice?.outstanding
                                    ? currency.format(
                                          Number(selectedInvoice.outstanding),
                                      )
                                    : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                Situação
                            </dt>
                            <dd>{selectedInvoice?.statusLabel ?? '—'}</dd>
                        </div>
                    </dl>
                ) : null}

                <div className="grid gap-2 sm:grid-cols-2">
                    {!showInvoicePayment && (
                        <label className="grid gap-1">
                            <span className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                Empresa
                            </span>
                            <Input
                                value={payee}
                                disabled={entry.is_reconciled}
                                onChange={(event) =>
                                    setPayee(event.target.value)
                                }
                                onBlur={() => {
                                    if (payee !== displayedPayee) {
                                        classify(payee, leafCategoryId);
                                    }
                                }}
                                placeholder="Beneficiário"
                                className="h-8"
                            />
                        </label>
                    )}
                    <label className="grid gap-1">
                        <span className="text-muted-foreground text-[11px] tracking-wide uppercase">
                            {showInvoicePayment
                                ? 'Fatura correspondente'
                                : 'Lançamento relacionado'}
                        </span>
                        <Select
                            value={matchId === '' ? 'none' : matchId}
                            disabled={
                                entry.is_reconciled ||
                                entry.candidates.length === 0
                            }
                            onValueChange={(value) =>
                                setMatchId(value === 'none' ? '' : value)
                            }
                        >
                            <SelectTrigger size="sm" className="w-full">
                                <SelectValue placeholder="Selecionar" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Sem correspondência
                                </SelectItem>
                                {entry.candidates.map((candidate) => {
                                    const id = candidateMatchId(
                                        entry,
                                        candidate,
                                    );

                                    return (
                                        <SelectItem key={id} value={id}>
                                            {candidate.description}
                                            {candidate.is_suggestion
                                                ? ' · sugerido'
                                                : ''}
                                        </SelectItem>
                                    );
                                })}
                            </SelectContent>
                        </Select>
                    </label>
                    {!showInvoicePayment && (
                        <>
                            <label className="grid gap-1">
                                <span className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                    Categoria
                                </span>
                                <Select
                                    value={
                                        selectedParentId === ''
                                            ? 'none'
                                            : selectedParentId
                                    }
                                    disabled={entry.is_reconciled}
                                    onValueChange={(value) => {
                                        const next =
                                            value === 'none' ? '' : value;
                                        setSelectedParentId(next);
                                        setSelectedSubId('');
                                        classify(
                                            payee,
                                            next === '' ? null : Number(next),
                                        );
                                    }}
                                >
                                    <SelectTrigger
                                        size="sm"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Categoria" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Sem categoria
                                        </SelectItem>
                                        {parents.map((category) => (
                                            <SelectItem
                                                key={category.id}
                                                value={String(category.id)}
                                            >
                                                {category.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </label>
                            <label className="grid gap-1">
                                <span className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                    Subcategoria
                                </span>
                                <Select
                                    value={
                                        selectedSubId === ''
                                            ? 'none'
                                            : selectedSubId
                                    }
                                    disabled={
                                        entry.is_reconciled ||
                                        children.length === 0
                                    }
                                    onValueChange={(value) => {
                                        const next =
                                            value === 'none' ? '' : value;
                                        setSelectedSubId(next);
                                        classify(
                                            payee,
                                            next === ''
                                                ? selectedParentId === ''
                                                    ? null
                                                    : Number(selectedParentId)
                                                : Number(next),
                                        );
                                    }}
                                >
                                    <SelectTrigger
                                        size="sm"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Subcategoria" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Nenhuma
                                        </SelectItem>
                                        {children.map((category) => (
                                            <SelectItem
                                                key={category.id}
                                                value={String(category.id)}
                                            >
                                                {category.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </label>
                        </>
                    )}
                </div>

                <p className="text-muted-foreground text-xs">
                    {[
                        relatedType,
                        relatedAccount,
                        competence
                            ? `competência ${formatReconciliationDate(competence)}`
                            : null,
                        relatedLabel && relatedLabel !== entry.description
                            ? relatedLabel
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ') || 'Sem lançamento interno ainda'}
                </p>
            </div>

            <div className="flex flex-col gap-2 lg:min-w-52">
                {entry.is_reconciled ? (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={undo}
                        >
                            <RotateCcw />
                            Desfazer
                        </Button>
                        {entry.matcher_rule_id == null &&
                            !entry.is_likely_invoice_payment && (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={`${createRule.url()}${classificationRuleCreateQuery(rulePrompt())}`}
                                >
                                    <ListFilter />
                                    Criar regra
                                </Link>
                            </Button>
                        )}
                    </>
                ) : (
                    <>
                        <Button
                            type="button"
                            size="sm"
                            disabled={matchId === ''}
                            onClick={conciliate}
                        >
                            <Link2 />
                            Conciliar
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={!canCreate}
                            onClick={createEntry}
                        >
                            <Plus />
                            Criar lançamento
                        </Button>
                        {entry.kind === 'statement' &&
                            !showInvoicePayment &&
                            counterpartOptions.length > 0 &&
                            (showTransfer ? (
                                <div className="flex flex-col gap-2">
                                    <Select
                                        value={
                                            counterpartId === ''
                                                ? 'none'
                                                : counterpartId
                                        }
                                        onValueChange={(value) =>
                                            setCounterpartId(
                                                value === 'none' ? '' : value,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            size="sm"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Outra conta" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                Outra conta
                                            </SelectItem>
                                            {counterpartOptions.map(
                                                (account) => (
                                                    <SelectItem
                                                        key={account.id}
                                                        value={String(
                                                            account.id,
                                                        )}
                                                    >
                                                        {account.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        disabled={counterpartId === ''}
                                        onClick={markTransfer}
                                    >
                                        Confirmar transferência
                                    </Button>
                                </div>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setShowTransfer(true)}
                                >
                                    <ArrowLeftRight />
                                    Marcar como transferência
                                </Button>
                            ))}
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={ignore}
                        >
                            <Ban />
                            Ignorar
                        </Button>
                    </>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => onOpenDetails(entry)}
                >
                    <Eye />
                    Ver detalhes
                </Button>
            </div>
        </article>
    );
}
