import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import CategoryController from '@/actions/App/Http/Controllers/CategoryController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/categories';
import type { Category, CategoryParentOption } from '@/types';

type Props = {
    category?: Category;
    parentOptions: CategoryParentOption[];
};

export default function CategoryForm({ category, parentOptions }: Props) {
    const [parentSelection, setParentSelection] = useState(
        category?.parent_id ? String(category.parent_id) : 'root',
    );
    const form = category
        ? CategoryController.update.form(category.id)
        : CategoryController.store.form();

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={!category}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Nome</Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={category?.name}
                            placeholder="Ex.: Moradia"
                            maxLength={120}
                            required
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="parent_id">Organização</Label>
                        <input
                            type="hidden"
                            name="parent_id"
                            value={
                                parentSelection === 'root'
                                    ? ''
                                    : parentSelection
                            }
                        />
                        <Select
                            value={parentSelection}
                            onValueChange={setParentSelection}
                            disabled={category?.has_children}
                        >
                            <SelectTrigger id="parent_id" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="root">
                                    Categoria principal
                                </SelectItem>
                                {parentOptions.map((parent) => (
                                    <SelectItem
                                        key={parent.id}
                                        value={String(parent.id)}
                                    >
                                        {parent.name}
                                        {parent.is_active ? '' : ' (inativa)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-muted-foreground text-xs">
                            {category?.has_children
                                ? 'Esta categoria possui subcategorias e deve permanecer como principal.'
                                : 'Escolha uma categoria principal somente para criar uma subcategoria.'}
                        </p>
                        <InputError message={errors.parent_id} />
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
                            {category
                                ? 'Salvar alterações'
                                : 'Cadastrar categoria'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
