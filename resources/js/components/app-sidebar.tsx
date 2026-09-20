import { Link } from '@inertiajs/react';
import {
    ArrowLeftRight,
    CreditCard,
    Landmark,
    LayoutGrid,
    ReceiptText,
    Tags,
    Users,
    WalletCards,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as accountsIndex } from '@/routes/accounts';
import { index as categoriesIndex } from '@/routes/categories';
import { index as creditCardInvoicesIndex } from '@/routes/credit-card-invoices';
import { index as creditCardsIndex } from '@/routes/credit-cards';
import { index as familyMembersIndex } from '@/routes/family-members';
import { index as transactionsIndex } from '@/routes/transactions';
import { index as transfersIndex } from '@/routes/transfers';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Visão geral',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Lançamentos',
        href: transactionsIndex(),
        icon: ReceiptText,
    },
    {
        title: 'Contas',
        href: accountsIndex(),
        icon: Landmark,
    },
    {
        title: 'Cartões',
        href: creditCardsIndex(),
        icon: CreditCard,
    },
    {
        title: 'Faturas',
        href: creditCardInvoicesIndex(),
        icon: WalletCards,
    },
    {
        title: 'Transferências',
        href: transfersIndex(),
        icon: ArrowLeftRight,
    },
    {
        title: 'Categorias',
        href: categoriesIndex(),
        icon: Tags,
    },
    {
        title: 'Pessoas',
        href: familyMembersIndex(),
        icon: Users,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="px-3 pt-4 pb-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <p className="text-sidebar-foreground/60 px-2 pb-2 text-xs leading-relaxed group-data-[collapsible=icon]:hidden">
                    Organize hoje para viver melhor amanhã.
                </p>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
