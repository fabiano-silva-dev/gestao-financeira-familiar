import { Head } from '@inertiajs/react';
import CategoryForm from '@/components/categories/category-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { create, index } from '@/routes/categories';
import type { CategoryParentOption, CategoryTypeOption } from '@/types';

export default function CategoriesCreate({
    parentOptions,
    typeOptions,
}: {
    parentOptions: CategoryParentOption[];
    typeOptions: CategoryTypeOption[];
}) {
    return (
        <>
            <Head title="Nova categoria" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Nova categoria
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Organize as movimentações em categorias e subcategorias.
                    </p>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Dados da categoria</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <CategoryForm
                            parentOptions={parentOptions}
                            typeOptions={typeOptions}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CategoriesCreate.layout = {
    breadcrumbs: [
        { title: 'Categorias', href: index() },
        { title: 'Nova categoria', href: create() },
    ],
};
