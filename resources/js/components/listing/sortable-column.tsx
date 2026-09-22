import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ListingSortDirection } from '@/types/listing';

type Props = {
    column: string;
    label: string;
    sort: string;
    direction: ListingSortDirection;
    onSort: (column: string) => void;
    align?: 'left' | 'right';
    className?: string;
};

export function SortableColumn({
    column,
    label,
    sort,
    direction,
    onSort,
    align = 'left',
    className,
}: Props) {
    const active = sort === column;
    const Icon = active ? (direction === 'asc' ? ArrowUp : ArrowDown) : ArrowUpDown;

    return (
        <button
            type="button"
            onClick={() => onSort(column)}
            className={cn(
                'inline-flex items-center gap-1 tracking-wide uppercase transition-colors hover:text-foreground',
                active ? 'text-foreground' : 'text-muted-foreground',
                align === 'right' && 'ml-auto flex-row-reverse',
                className,
            )}
        >
            <span>{label}</span>
            <Icon className="size-3.5 shrink-0 opacity-70" />
        </button>
    );
}
