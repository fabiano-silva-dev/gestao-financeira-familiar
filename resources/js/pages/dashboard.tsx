import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as accountsIndex } from '@/routes/accounts';

export default function Dashboard() {
    const { workspace } = usePage().props;

    return (
        <>
            <Head title="Início" />
            <div className="flex h-full flex-1 flex-col p-4 md:p-6">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle className="text-2xl">
                            Gestão Financeira Familiar
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-muted-foreground">
                            Comece cadastrando as contas onde o dinheiro da
                            família está guardado.
                        </p>
                        <p className="text-sm">
                            Workspace atual:{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>
                        </p>
                        <Button asChild>
                            <Link href={accountsIndex()}>
                                Gerenciar contas financeiras
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Início',
            href: dashboard(),
        },
    ],
};
