import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ArrowUpRight } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type Tone = 'primary' | 'positive' | 'destructive' | 'warning';

const toneClasses: Record<Tone, string> = {
    primary: 'bg-secondary text-primary',
    positive: 'bg-positive-muted text-positive',
    destructive: 'bg-destructive/10 text-destructive',
    warning: 'bg-warning-muted text-warning-foreground',
};

type Props = {
    title: string;
    value: string;
    description: string;
    icon: LucideIcon;
    tone?: Tone;
    valueClassName?: string;
    href: NonNullable<InertiaLinkProps['href']>;
    ariaLabel: string;
};

export function MetricCard({
    title,
    value,
    description,
    icon: Icon,
    tone = 'primary',
    valueClassName,
    href,
    ariaLabel,
}: Props) {
    return (
        <Link
            href={href}
            aria-label={ariaLabel}
            className="group focus-visible:ring-ring block h-full rounded-xl focus-visible:ring-2 focus-visible:outline-none"
        >
            <Card className="hover:bg-muted/35 h-full gap-0 py-0 transition-colors">
                <CardContent className="p-5">
                    <div className="flex items-start justify-between gap-4">
                        <div className="min-w-0">
                            <p className="text-muted-foreground text-sm font-medium">
                                {title}
                            </p>
                            <p
                                className={cn(
                                    'mt-2 truncate text-2xl font-semibold tracking-tight tabular-nums',
                                    valueClassName,
                                )}
                                title={value}
                            >
                                {value}
                            </p>
                        </div>
                        <div className="flex shrink-0 items-start gap-2">
                            <div
                                className={cn(
                                    'flex size-10 items-center justify-center rounded-xl',
                                    toneClasses[tone],
                                )}
                            >
                                <Icon className="size-5" aria-hidden="true" />
                            </div>
                            <ArrowUpRight
                                className="text-muted-foreground mt-1 size-4 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                                aria-hidden="true"
                            />
                        </div>
                    </div>
                    <p className="text-muted-foreground mt-3 text-xs">
                        {description}
                    </p>
                </CardContent>
            </Card>
        </Link>
    );
}
