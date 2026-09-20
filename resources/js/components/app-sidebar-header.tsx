import { usePage } from '@inertiajs/react';
import { House } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { workspace } = usePage().props;

    return (
        <header className="bg-background/90 sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between gap-3 border-b px-4 backdrop-blur-sm transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:px-6">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            {workspace.current && (
                <div className="bg-card flex min-w-0 items-center gap-2 rounded-lg border px-2.5 py-1.5 shadow-xs sm:px-3">
                    <div className="bg-secondary text-primary flex size-7 shrink-0 items-center justify-center rounded-md">
                        <House className="size-3.5" aria-hidden="true" />
                    </div>
                    <div className="min-w-0 leading-tight">
                        <p className="text-muted-foreground hidden text-[10px] font-medium tracking-wide uppercase sm:block">
                            Espaço familiar
                        </p>
                        <p className="max-w-28 truncate text-xs font-semibold sm:max-w-44">
                            {workspace.current.name}
                        </p>
                    </div>
                </div>
            )}
        </header>
    );
}
