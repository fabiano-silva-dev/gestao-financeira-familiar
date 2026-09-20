import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-9 items-center justify-center rounded-lg shadow-sm">
                <AppLogoIcon className="size-5" />
            </div>
            <div className="ml-1.5 grid flex-1 text-left">
                <span className="truncate text-sm leading-tight font-semibold">
                    {name}
                </span>
                <span className="text-sidebar-foreground/60 truncate text-[11px] leading-tight">
                    Organização familiar
                </span>
            </div>
        </>
    );
}
