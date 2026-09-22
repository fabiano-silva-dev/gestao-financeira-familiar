import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { FinancialEntryType } from '@/types';

type TypeOption = {
    value: string;
    label: string;
};

type Props = {
    value: FinancialEntryType;
    options: TypeOption[];
    onChange: (type: FinancialEntryType) => void;
};

export default function EntryTypeSwitcher({ value, options, onChange }: Props) {
    return (
        <div className="grid gap-2">
            <Label id="entry-type-label">Tipo do lançamento</Label>
            <ToggleGroup
                type="single"
                variant="outline"
                value={value}
                onValueChange={(nextValue) => {
                    if (
                        nextValue === 'income' ||
                        nextValue === 'expense' ||
                        nextValue === 'transfer'
                    ) {
                        onChange(nextValue);
                    }
                }}
                className="grid w-full grid-cols-3"
                aria-labelledby="entry-type-label"
            >
                {options.map((option) => (
                    <ToggleGroupItem
                        key={option.value}
                        value={option.value}
                        className="w-full"
                    >
                        {option.label}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
            <p className="text-muted-foreground text-xs">
                {value === 'transfer'
                    ? 'Movimenta saldo entre contas próprias, sem criar receita ou despesa.'
                    : value === 'expense'
                      ? 'Registre o gasto, o vencimento e como ele será pago.'
                      : 'Registre a entrada realizada ou prevista.'}
            </p>
        </div>
    );
}
