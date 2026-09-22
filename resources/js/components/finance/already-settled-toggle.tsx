import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

type Props = {
    checked: boolean;
    isExpense: boolean;
    onCheckedChange: (checked: boolean) => void;
    description: string;
    disabled?: boolean;
    name?: string;
};

export function AlreadySettledToggle({
    checked,
    isExpense,
    onCheckedChange,
    description,
    disabled = false,
    name = 'already_settled',
}: Props) {
    const label = isExpense ? 'Já paguei' : 'Já recebi';

    return (
        <div className="space-y-2">
            <p className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
                Ajustes
            </p>
            <input type="hidden" name={name} value={checked ? '1' : '0'} />
            <div className="bg-muted/40 flex items-center justify-between gap-4 rounded-full px-4 py-3">
                <Label
                    htmlFor={name}
                    className={`text-sm font-medium ${disabled ? '' : 'cursor-pointer'}`}
                >
                    {label}
                </Label>
                <Switch
                    id={name}
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={onCheckedChange}
                    aria-label={label}
                />
            </div>
            <p className="text-muted-foreground text-xs">{description}</p>
        </div>
    );
}
