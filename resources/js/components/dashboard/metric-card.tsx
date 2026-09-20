import type { LucideIcon } from 'lucide-react';
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
};

export function MetricCard({
    title,
    value,
    description,
    icon: Icon,
    tone = 'primary',
    valueClassName,
}: Props) {
    return (
        <Card className="gap-0 py-0">
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
                    <div
                        className={cn(
                            'flex size-10 shrink-0 items-center justify-center rounded-xl',
                            toneClasses[tone],
                        )}
                    >
                        <Icon className="size-5" aria-hidden="true" />
                    </div>
                </div>
                <p className="text-muted-foreground mt-3 text-xs">
                    {description}
                </p>
            </CardContent>
        </Card>
    );
}
