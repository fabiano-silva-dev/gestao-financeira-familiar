import { Link } from '@inertiajs/react';
import { CalendarCheck2, FileUp, History, ListChecks } from 'lucide-react';
import { Button } from '@/components/ui/button';

type Props = {
    active: 'upload' | 'closing' | 'reconciliation' | 'history';
};

const items = [
    {
        key: 'upload' as const,
        label: 'Importar arquivo',
        href: '/importacoes',
        icon: FileUp,
    },
    {
        key: 'closing' as const,
        label: 'Fechamento mensal',
        href: '/importacoes/fechamento',
        icon: CalendarCheck2,
    },
    {
        key: 'reconciliation' as const,
        label: 'Conciliação',
        href: '/conciliacao',
        icon: ListChecks,
    },
    {
        key: 'history' as const,
        label: 'Histórico de arquivos',
        href: '/importacoes#historico',
        icon: History,
    },
];

export function ImportsNavigation({ active }: Props) {
    return (
        <nav className="flex flex-wrap gap-2" aria-label="Navegação de importações">
            {items.map((item) => {
                const Icon = item.icon;

                return (
                    <Button
                        key={item.key}
                        variant={active === item.key ? 'secondary' : 'outline'}
                        size="sm"
                        asChild
                    >
                        <Link href={item.href}>
                            <Icon />
                            {item.label}
                        </Link>
                    </Button>
                );
            })}
        </nav>
    );
}
