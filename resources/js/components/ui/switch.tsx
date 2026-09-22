import type { ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type SwitchProps = Omit<
    ButtonHTMLAttributes<HTMLButtonElement>,
    'onChange' | 'role' | 'type'
> & {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
};

export function Switch({
    checked,
    onCheckedChange,
    className,
    disabled,
    id,
    ...props
}: SwitchProps) {
    return (
        <button
            {...props}
            id={id}
            type="button"
            role="switch"
            aria-checked={checked}
            disabled={disabled}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'focus-visible:ring-ring relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-primary' : 'bg-input',
                className,
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'pointer-events-none block size-5 rounded-full bg-white shadow-sm ring-0 transition-transform',
                    checked ? 'translate-x-5' : 'translate-x-0',
                )}
            />
        </button>
    );
}
