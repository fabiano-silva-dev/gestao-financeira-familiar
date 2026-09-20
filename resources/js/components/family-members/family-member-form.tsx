import { Form, Link } from '@inertiajs/react';
import FamilyMemberController from '@/actions/App/Http/Controllers/FamilyMemberController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/family-members';
import type { FamilyMember } from '@/types';

export default function FamilyMemberForm({
    member,
}: {
    member?: FamilyMember;
}) {
    const form = member
        ? FamilyMemberController.update.form(member.id)
        : FamilyMemberController.store.form();

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={!member}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Nome da pessoa</Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={member?.name}
                            placeholder="Ex.: Fabiano"
                            maxLength={120}
                            required
                            autoFocus
                        />
                        <p className="text-muted-foreground text-xs">
                            Use este cadastro para identificar o responsável por
                            cada movimentação.
                        </p>
                        <InputError message={errors.name} />
                    </div>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button
                            variant="outline"
                            className="w-full sm:w-auto"
                            asChild
                        >
                            <Link href={index()}>Cancelar</Link>
                        </Button>
                        <Button
                            className="w-full sm:w-auto"
                            disabled={processing}
                        >
                            {member ? 'Salvar alterações' : 'Cadastrar pessoa'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
