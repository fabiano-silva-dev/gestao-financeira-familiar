import { Head, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';

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
                    <CardContent className="space-y-2">
                        <p className="text-muted-foreground">
                            A fundação do seu espaço financeiro está pronta.
                        </p>
                        <p className="text-sm">
                            Workspace atual:{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>
                        </p>
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
