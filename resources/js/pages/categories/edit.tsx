import { Head } from '@inertiajs/react';
import CategoryForm from '@/components/categories/category-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/categories';
import type {
    Category,
    CategoryParentOption,
    CategoryTypeOption,
} from '@/types';

export default function CategoriesEdit({
    category,
    parentOptions,
    typeOptions,
}: {
    category: Category;
    parentOptions: CategoryParentOption[];
    typeOptions: CategoryTypeOption[];
}) {
    return (
        <>
            <Head title={`Editar ${category.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar categoria
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Atualize os dados de {category.name}.
                    </p>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Dados da categoria</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <CategoryForm
                            category={category}
                            parentOptions={parentOptions}
                            typeOptions={typeOptions}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CategoriesEdit.layout = {
    breadcrumbs: [
        { title: 'Categorias', href: index() },
        { title: 'Editar categoria', href: index() },
    ],
};
