import { router } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import { visitListing } from '@/lib/listing';
import { dashboard } from '@/routes';
import type { ListingQueryState } from '@/types/listing';

const monthYear = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

function yearMonthFromPeriod(period: string) {
    return period.slice(0, 7);
}

function shiftYearMonth(yearMonth: string, delta: number) {
    const [year, month] = yearMonth.split('-').map(Number);
    const next = new Date(Date.UTC(year, month - 1 + delta, 1));

    return `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}`;
}

type Props = {
    currentPeriod: string;
    url?: string;
    query?: ListingQueryState;
};

export function MonthSelector({ currentPeriod, url, query }: Props) {
    const yearMonth = yearMonthFromPeriod(currentPeriod);
    const periodLabel = monthYear.format(
        new Date(`${currentPeriod}T00:00:00Z`),
    );
    const visitPeriod = (period: string) => {
        if (url !== undefined && query !== undefined) {
            visitListing(url, query, { period });

            return;
        }

        router.get(
            url ?? dashboard.url(),
            { period },
            {
                preserveScroll: true,
                replace: true,
            },
        );
    };

    return (
        <div className="bg-card inline-flex h-9 items-center overflow-hidden rounded-md border">
            <button
                type="button"
                className="hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring/50 inline-flex size-9 items-center justify-center transition-colors focus-visible:ring-[3px] focus-visible:outline-none"
                aria-label="Mês anterior"
                onClick={() => visitPeriod(shiftYearMonth(yearMonth, -1))}
            >
                <ChevronLeft className="size-4" />
            </button>
            <label className="relative inline-flex min-w-[10.5rem] cursor-pointer items-center justify-center gap-2 px-1 text-sm font-medium">
                <CalendarDays
                    className="text-primary size-4"
                    aria-hidden="true"
                />
                <span className="inline-block first-letter:uppercase">
                    {periodLabel}
                </span>
                <span className="sr-only">Selecionar mês</span>
                <input
                    type="month"
                    value={yearMonth}
                    min="1990-01"
                    max="2100-12"
                    onChange={(event) => {
                        if (event.target.value) {
                            visitPeriod(event.target.value);
                        }
                    }}
                    className="absolute inset-0 cursor-pointer opacity-0"
                />
            </label>
            <button
                type="button"
                className="hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring/50 inline-flex size-9 items-center justify-center transition-colors focus-visible:ring-[3px] focus-visible:outline-none"
                aria-label="Próximo mês"
                onClick={() => visitPeriod(shiftYearMonth(yearMonth, 1))}
            >
                <ChevronRight className="size-4" />
            </button>
        </div>
    );
}
