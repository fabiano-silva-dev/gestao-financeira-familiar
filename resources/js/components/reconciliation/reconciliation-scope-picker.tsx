import { router } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
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
import { listingUrl, visitListing } from '@/lib/listing';
import { reprocess } from '@/routes/reconciliation';
import type {
    ListingQueryState,
    ReconciliationAccountOption,
    ReconciliationCardOption,
    ReconciliationFilters,
    ReconciliationImportOption,
} from '@/types';

type Props = {
    url: string;
    filters: ReconciliationFilters & ListingQueryState;
    accountOptions: ReconciliationAccountOption[];
    cardOptions: ReconciliationCardOption[];
    importOptions: ReconciliationImportOption[];
};

export function ReconciliationScopePicker({
    url,
    filters,
    accountOptions,
    cardOptions,
    importOptions,
}: Props) {
    const showAccount = filters.kind !== 'invoice';
    const showCard = filters.kind !== 'statement';
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const selectedImport = importOptions.find(
        (option) => option.id === filters.import,
    );

    const update = (patch: Record<string, string | number | null>) => {
        visitListing(url, filters, patch);
    };

    const runReprocess = () => {
        if (filters.import === null) {
            return;
        }

        router.post(
            listingUrl(reprocess.url(filters.import), filters),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                },
            },
        );
    };

    return (
        <div className="bg-card rounded-xl border p-4 shadow-sm">
            <div className="mb-3">
                <p className="font-medium">Recorte da conciliação</p>
                <p className="text-muted-foreground mt-1 text-sm">
                    Escolha uma conta e um mês, um período ou um arquivo
                    importado. Sem esse recorte, a lista não abre.
                </p>
            </div>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div className="grid gap-1.5">
                    <Label htmlFor="reconciliation-kind">Origem</Label>
                    <Select
                        value={filters.kind}
                        onValueChange={(value) =>
                            update({
                                kind: value,
                                account:
                                    value === 'invoice' ? null : filters.account,
                                card:
                                    value === 'statement' ? null : filters.card,
                            })
                        }
                    >
                        <SelectTrigger id="reconciliation-kind" className="w-full">
                            <SelectValue placeholder="Origem" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Extratos e faturas</SelectItem>
                            <SelectItem value="statement">Extratos</SelectItem>
                            <SelectItem value="invoice">Faturas</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {showAccount && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="reconciliation-account">Conta</Label>
                        <Select
                            value={
                                filters.account
                                    ? String(filters.account)
                                    : 'none'
                            }
                            onValueChange={(value) =>
                                update({
                                    account: value === 'none' ? null : value,
                                    import: null,
                                })
                            }
                        >
                            <SelectTrigger
                                id="reconciliation-account"
                                className="w-full"
                            >
                                <SelectValue placeholder="Selecione a conta" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Selecione a conta
                                </SelectItem>
                                {accountOptions.map((account) => (
                                    <SelectItem
                                        key={account.id}
                                        value={String(account.id)}
                                    >
                                        {account.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}

                {showCard && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="reconciliation-card">Cartão</Label>
                        <Select
                            value={filters.card ? String(filters.card) : 'none'}
                            onValueChange={(value) =>
                                update({
                                    card: value === 'none' ? null : value,
                                    import: null,
                                })
                            }
                        >
                            <SelectTrigger
                                id="reconciliation-card"
                                className="w-full"
                            >
                                <SelectValue placeholder="Selecione o cartão" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Selecione o cartão
                                </SelectItem>
                                {cardOptions.map((card) => (
                                    <SelectItem
                                        key={card.id}
                                        value={String(card.id)}
                                    >
                                        {card.name} · final {card.last_four}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}

                <div className="grid gap-1.5">
                    <Label htmlFor="reconciliation-period">Mês</Label>
                    <Input
                        id="reconciliation-period"
                        type="month"
                        value={filters.period ?? ''}
                        min="1990-01"
                        max="2100-12"
                        onChange={(event) =>
                            update({
                                period: event.target.value || null,
                                from: null,
                                to: null,
                                import: null,
                            })
                        }
                    />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="reconciliation-from">Período de</Label>
                    <Input
                        id="reconciliation-from"
                        type="date"
                        value={filters.from ?? ''}
                        onChange={(event) =>
                            update({
                                from: event.target.value || null,
                                period: null,
                                import: null,
                            })
                        }
                    />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="reconciliation-to">Até</Label>
                    <Input
                        id="reconciliation-to"
                        type="date"
                        value={filters.to ?? ''}
                        onChange={(event) =>
                            update({
                                to: event.target.value || null,
                                period: null,
                                import: null,
                            })
                        }
                    />
                </div>

                <div className="grid gap-1.5 md:col-span-2">
                    <Label htmlFor="reconciliation-import">Arquivo</Label>
                    <Select
                        value={
                            filters.import ? String(filters.import) : 'none'
                        }
                        onValueChange={(value) => {
                            if (value === 'none') {
                                update({ import: null });

                                return;
                            }

                            const selected = importOptions.find(
                                (option) => String(option.id) === value,
                            );

                            update({
                                import: value,
                                kind: selected?.kind ?? filters.kind,
                                account: null,
                                card: null,
                                period: null,
                                from: null,
                                to: null,
                            });
                        }}
                    >
                        <SelectTrigger
                            id="reconciliation-import"
                            className="w-full"
                        >
                            <SelectValue placeholder="Selecione um arquivo" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">
                                Selecione um arquivo
                            </SelectItem>
                            {importOptions.map((option) => (
                                <SelectItem
                                    key={option.id}
                                    value={String(option.id)}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {selectedImport && (
                        <div className="flex justify-end">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setConfirmOpen(true)}
                            >
                                <RotateCcw />
                                Reabrir e processar
                            </Button>
                        </div>
                    )}
                </div>
            </div>

            <Dialog
                open={confirmOpen}
                onOpenChange={(open) => {
                    if (!processing) {
                        setConfirmOpen(open);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Reabrir esta importação?</DialogTitle>
                        <DialogDescription>
                            Os vínculos desta importação serão desfeitos e os
                            movimentos voltarão a ser processados com as regras
                            atuais. Lançamentos internos já existentes não
                            serão duplicados.
                        </DialogDescription>
                    </DialogHeader>
                    <p className="bg-muted rounded-lg px-3 py-2 text-sm">
                        {selectedImport?.label}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setConfirmOpen(false)}
                            disabled={processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="button"
                            onClick={runReprocess}
                            disabled={processing}
                        >
                            {processing
                                ? 'Processando…'
                                : 'Reabrir e processar'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
