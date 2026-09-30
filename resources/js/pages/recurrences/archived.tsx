import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/recurrences';

type Props = {
    description: string;
};

export default function RecurrenceArchived({ description }: Props) {
    return (
        <>
            <Head title="Recorrência excluída" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button variant="ghost" size="sm" className="mb-2" asChild>
                        <Link href={index()}>
                            <ArrowLeft />
                            Voltar para a lista
                        </Link>
                    </Button>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Recorrência excluída
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {description} não gera mais lançamentos.
                    </p>
                </div>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>O que permanece</CardTitle>
                    </CardHeader>
                    <CardContent className="text-muted-foreground space-y-3 text-sm">
                        <p>
                            Pagamentos, recebimentos e ocorrências já
                            registradas continuam no histórico.
                        </p>
                        <p>
                            Para tirar uma data específica, abra o lançamento e
                            use excluir esta ocorrência.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

RecurrenceArchived.layout = {
    breadcrumbs: [
        { title: 'Recorrências', href: index() },
        { title: 'Recorrência excluída', href: index() },
    ],
};
