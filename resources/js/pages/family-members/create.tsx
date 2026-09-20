import { Head } from '@inertiajs/react';
import FamilyMemberForm from '@/components/family-members/family-member-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { create, index } from '@/routes/family-members';

export default function FamilyMembersCreate() {
    return (
        <>
            <Head title="Nova pessoa" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Nova pessoa
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Cadastre quem participa da organização financeira da
                        família.
                    </p>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Dados da pessoa</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <FamilyMemberForm />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

FamilyMembersCreate.layout = {
    breadcrumbs: [
        { title: 'Pessoas', href: index() },
        { title: 'Nova pessoa', href: create() },
    ],
};
