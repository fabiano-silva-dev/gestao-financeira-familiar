import { Check, ChevronDown, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type {
    FinancialEntryReferenceOption,
    FinancialEntryType,
} from '@/types';

type CategoryPickerProps = {
    value: number | null;
    currentLabel?: string | null;
    options: FinancialEntryReferenceOption[];
    type?: Exclude<FinancialEntryType, 'transfer'> | null;
    onChange: (categoryId: number | null) => void;
    disabled?: boolean;
    busy?: boolean;
    triggerLabel?: string;
    confirmLabel?: string;
};

function typeLabel(type: FinancialEntryReferenceOption['type']) {
    return type === 'income' ? 'Receita' : 'Despesa';
}

function hierarchyLabel(option: FinancialEntryReferenceOption) {
    const path = (option.label ?? option.name).replaceAll(' / ', ' > ');

    return typeLabel(option.type) + ' > ' + path;
}

export function CategoryPicker({
    value,
    currentLabel,
    options,
    type = null,
    onChange,
    disabled = false,
    busy = false,
    triggerLabel,
    confirmLabel,
}: CategoryPickerProps) {
    const rootRef = useRef<HTMLDivElement>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [draftValue, setDraftValue] = useState<number | null>(value);

    useEffect(() => {
        if (!open) {
            return;
        }

        const closeOnOutsideClick = (event: PointerEvent) => {
            if (
                rootRef.current &&
                !rootRef.current.contains(event.target as Node)
            ) {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', closeOnOutsideClick);
        const focusTimer = window.setTimeout(() => searchRef.current?.focus());

        return () => {
            document.removeEventListener('pointerdown', closeOnOutsideClick);
            window.clearTimeout(focusTimer);
        };
    }, [open]);

    useEffect(() => {
        if (!open) {
            setDraftValue(value);
        }
    }, [open, value]);

    const filteredOptions = useMemo(() => {
        const normalizedSearch = search.trim().toLocaleLowerCase('pt-BR');

        return options.filter((option) => {
            if (type !== null && option.type !== type) {
                return false;
            }

            if (normalizedSearch === '') {
                return true;
            }

            return hierarchyLabel(option)
                .toLocaleLowerCase('pt-BR')
                .includes(normalizedSearch);
        });
    }, [options, search, type]);

    const choose = (categoryId: number | null) => {
        if (confirmLabel) {
            setDraftValue(categoryId);

            return;
        }

        setOpen(false);
        onChange(categoryId);
    };

    const confirm = () => {
        setOpen(false);
        onChange(draftValue);
    };

    const toggle = () => {
        setSearch('');
        setDraftValue(value);
        setOpen((current) => !current);
    };

    return (
        <div ref={rootRef} className="relative min-w-0">
            <Button
                type="button"
                variant="ghost"
                size="sm"
                disabled={disabled || busy}
                onClick={toggle}
                className="hover:bg-accent h-auto min-h-9 w-full min-w-0 justify-between gap-2 px-2 text-left font-normal"
                aria-haspopup="listbox"
                aria-expanded={open}
            >
                <span
                    className={
                        value === null && !triggerLabel
                            ? 'text-muted-foreground truncate'
                            : 'truncate'
                    }
                >
                    {busy
                        ? 'Salvando…'
                        : (triggerLabel ??
                          currentLabel ??
                          'Selecionar categoria')}
                </span>
                <ChevronDown className="text-muted-foreground size-3.5 shrink-0" />
            </Button>

            {open && (
                <div className="bg-popover text-popover-foreground absolute top-full left-0 z-50 mt-1 w-[min(24rem,calc(100vw-2rem))] rounded-lg border p-2 shadow-md">
                    <div className="relative mb-2">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                        <Input
                            ref={searchRef}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Pesquisar categoria…"
                            className="h-9 pl-8"
                        />
                    </div>

                    <div
                        role="listbox"
                        aria-label="Categorias"
                        className="max-h-64 space-y-0.5 overflow-y-auto"
                    >
                        <button
                            type="button"
                            role="option"
                            aria-selected={draftValue === null}
                            onClick={() => choose(null)}
                            className="hover:bg-accent focus-visible:bg-accent flex min-h-9 w-full items-center justify-between rounded-md px-2 py-1.5 text-left text-sm outline-none"
                        >
                            <span className="text-muted-foreground">
                                Sem categoria
                            </span>
                            {draftValue === null && (
                                <Check className="size-4 shrink-0" />
                            )}
                        </button>

                        {filteredOptions.map((option) => (
                            <button
                                key={option.id}
                                type="button"
                                role="option"
                                aria-selected={draftValue === option.id}
                                onClick={() => choose(option.id)}
                                className="hover:bg-accent focus-visible:bg-accent flex min-h-9 w-full items-center justify-between gap-3 rounded-md px-2 py-1.5 text-left text-sm outline-none"
                            >
                                <span className="min-w-0">
                                    <span className="block truncate">
                                        {hierarchyLabel(option)}
                                    </span>
                                    {!option.is_active && (
                                        <span className="text-muted-foreground block text-xs">
                                            Categoria inativa
                                        </span>
                                    )}
                                </span>
                                {draftValue === option.id && (
                                    <Check className="size-4 shrink-0" />
                                )}
                            </button>
                        ))}

                        {filteredOptions.length === 0 && (
                            <p className="text-muted-foreground px-2 py-4 text-center text-sm">
                                Nenhuma categoria encontrada.
                            </p>
                        )}
                    </div>

                    {confirmLabel && (
                        <div className="mt-2 flex justify-end border-t pt-2">
                            <Button
                                type="button"
                                size="sm"
                                onClick={confirm}
                                disabled={busy}
                            >
                                {confirmLabel}
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
