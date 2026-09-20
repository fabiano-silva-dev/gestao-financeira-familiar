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

export function CashFlowChart({ points }: { points: CashFlowPoint[] }) {
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
                role="img"
                aria-label="Comparativo de receitas e despesas dos últimos seis meses"
            >
                {points.map((point) => {
                    const income = Number(point.income);
                    const expenses = Number(point.expenses);
                    const label = month.format(
                        new Date(`${point.month}T00:00:00Z`),
                    );

                    return (
                        <div
                            key={point.month}
                            className="flex min-w-0 flex-col items-center"
                            title={`${label}: receitas ${currency.format(income)}, despesas ${currency.format(expenses)}`}
                        >
                            <div className="flex min-h-0 w-full flex-1 items-end justify-center gap-1 sm:gap-2">
                                <div
                                    className="bg-positive/85 w-full max-w-5 rounded-t-md transition-[height]"
                                    style={{
                                        height: barHeight(income, maximum),
                                    }}
                                />
                                <div
                                    className="bg-destructive/70 w-full max-w-5 rounded-t-md transition-[height]"
                                    style={{
                                        height: barHeight(expenses, maximum),
                                    }}
                                />
                            </div>
                            <span className="text-muted-foreground mt-2 truncate text-[10px] font-medium capitalize sm:text-xs">
                                {label.replace('.', '')}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
