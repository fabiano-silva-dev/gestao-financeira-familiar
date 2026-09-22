import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { CashFlowPoint } from '@/types';

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const month = new Intl.DateTimeFormat('pt-BR', {
    month: 'short',
    timeZone: 'UTC',
});

function barHeight(value: number, maximum: number) {
    if (value <= 0) {
        return '0%';
    }

    return `${Math.max((value / maximum) * 100, 4)}%`;
}

function monthLabel(value: string) {
    return month.format(new Date(`${value}T00:00:00Z`)).replace('.', '');
}

export function CashFlowChart({
    points,
    monthHref,
    incomeHref,
    expensesHref,
}: {
    points: CashFlowPoint[];
    monthHref: (point: CashFlowPoint) => NonNullable<InertiaLinkProps['href']>;
    incomeHref: (point: CashFlowPoint) => NonNullable<InertiaLinkProps['href']>;
    expensesHref: (
        point: CashFlowPoint,
    ) => NonNullable<InertiaLinkProps['href']>;
}) {
    const maximum = Math.max(
        1,
        ...points.flatMap((point) => [
            Number(point.income),
            Number(point.expenses),
        ]),
    );

    return (
        <div>
            <div className="text-muted-foreground mb-5 flex flex-wrap items-center gap-4 text-xs">
                <span className="flex items-center gap-2">
                    <span className="bg-positive size-2.5 rounded-full" />
                    Receitas
                </span>
                <span className="flex items-center gap-2">
                    <span className="bg-destructive/75 size-2.5 rounded-full" />
                    Despesas
                </span>
            </div>

            <div
                className="grid h-52 grid-cols-6 gap-2 sm:gap-4"
                aria-label="Comparativo de receitas e despesas dos últimos seis meses"
            >
                {points.map((point) => {
                    const income = Number(point.income);
                    const expenses = Number(point.expenses);
                    const label = monthLabel(point.month);

                    return (
                        <div
                            key={point.month}
                            className="flex min-w-0 flex-col items-center"
                        >
                            <div className="flex min-h-0 w-full flex-1 items-end justify-center gap-1 sm:gap-2">
                                <Link
                                    href={incomeHref(point)}
                                    aria-label={`Receitas de ${label}: ${currency.format(income)}`}
                                    title={`Receitas de ${label}: ${currency.format(income)}`}
                                    className="bg-positive/85 hover:bg-positive focus-visible:ring-ring block w-full max-w-5 rounded-t-md transition-[height,opacity] hover:opacity-90 focus-visible:ring-2 focus-visible:outline-none"
                                    style={{
                                        height: barHeight(income, maximum),
                                    }}
                                />
                                <Link
                                    href={expensesHref(point)}
                                    aria-label={`Despesas de ${label}: ${currency.format(expenses)}`}
                                    title={`Despesas de ${label}: ${currency.format(expenses)}`}
                                    className="bg-destructive/70 hover:bg-destructive focus-visible:ring-ring block w-full max-w-5 rounded-t-md transition-[height,opacity] hover:opacity-90 focus-visible:ring-2 focus-visible:outline-none"
                                    style={{
                                        height: barHeight(expenses, maximum),
                                    }}
                                />
                            </div>
                            <Link
                                href={monthHref(point)}
                                className="text-muted-foreground hover:text-foreground focus-visible:ring-ring mt-2 truncate text-[10px] font-medium capitalize underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:outline-none sm:text-xs"
                            >
                                {label}
                            </Link>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
