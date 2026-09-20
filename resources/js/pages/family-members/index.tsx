import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Pencil, Plus, Power, UserRound, Users } from 'lucide-react';
import FamilyMemberController from '@/actions/App/Http/Controllers/FamilyMemberController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { create, edit, index } from '@/routes/family-members';
import type { FamilyMember } from '@/types';

type Props = {
    members: FamilyMember[];
};

export default function FamilyMembersIndex() {
    const { members, workspace } = usePage<Props>().props;

    return (
        <>
            <Head title="Pessoas" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Pessoas
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Responsáveis pelas movimentações de{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>
                            .
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Nova pessoa
                        </Link>
                    </Button>
                </div>

                {members.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <Users className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhuma pessoa cadastrada
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Cadastre quem poderá ser associado às
                                    receitas e despesas da família.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>
                                    Cadastrar primeira pessoa
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {members.map((member) => (
                            <Card
                                key={member.id}
                                className={
                                    member.is_active ? undefined : 'opacity-70'
                                }
                            >
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <div className="bg-muted rounded-full p-2">
                                                <UserRound className="size-5" />
                                            </div>
                                            <div className="min-w-0">
                                                <CardTitle className="truncate">
                                                    {member.name}
                                                </CardTitle>
                                                <CardDescription>
                                                    Pessoa da família
                                                </CardDescription>
                                            </div>
                                        </div>
                                        <Badge
                                            variant={
                                                member.is_active
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {member.is_active
                                                ? 'Ativa'
                                                : 'Inativa'}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardFooter className="flex flex-wrap justify-end gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={edit(member.id)}>
                                            <Pencil />
                                            Editar
                                        </Link>
                                    </Button>
                                    <Form
                                        {...FamilyMemberController.toggleStatus.form(
                                            member.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                <Power />
                                                {member.is_active
                                                    ? 'Desativar'
                                                    : 'Ativar'}
                                            </Button>
                                        )}
                                    </Form>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

FamilyMembersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Pessoas',
            href: index(),
        },
    ],
};
