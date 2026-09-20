import { Head } from '@inertiajs/react';
import FamilyMemberForm from '@/components/family-members/family-member-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/family-members';
import type { FamilyMember } from '@/types';

export default function FamilyMembersEdit({
    member,
}: {
    member: FamilyMember;
}) {
    return (
        <>
            <Head title={`Editar ${member.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar pessoa
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Atualize os dados de {member.name}.
                    </p>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Dados da pessoa</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <FamilyMemberForm member={member} />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

FamilyMembersEdit.layout = {
    breadcrumbs: [
        { title: 'Pessoas', href: index() },
        { title: 'Editar pessoa', href: index() },
    ],
};
