import { Form, Head, Link, usePage } from '@inertiajs/react';
import { FolderTree, Pencil, Plus, Power, Tags } from 'lucide-react';
import CategoryController from '@/actions/App/Http/Controllers/CategoryController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { create, edit, index } from '@/routes/categories';
import type { Category } from '@/types';

type Props = {
    categories: Category[];
};

function CategoryActions({ category }: { category: Category }) {
    return (
        <div className="flex flex-wrap justify-end gap-2">
            <Button variant="outline" size="sm" asChild>
                <Link href={edit(category.id)}>
                    <Pencil />
                    Editar
                </Link>
            </Button>
            <Form
                {...CategoryController.toggleStatus.form(category.id)}
                options={{ preserveScroll: true }}
            >
                {({ processing }) => (
                    <Button variant="ghost" size="sm" disabled={processing}>
                        <Power />
                        {category.is_active ? 'Desativar' : 'Ativar'}
                    </Button>
                )}
            </Form>
        </div>
    );
}

export default function CategoriesIndex() {
    const { categories, workspace } = usePage<Props>().props;

    return (
        <>
            <Head title="Categorias" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Categorias
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Classificação financeira de{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>
                            .
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Nova categoria
                        </Link>
                    </Button>
                </div>

                {categories.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <Tags className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhuma categoria cadastrada
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Crie categorias para organizar receitas e
                                    despesas com clareza.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>
                                    Cadastrar primeira categoria
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid items-start gap-4 xl:grid-cols-2">
                        {categories.map((category) => (
                            <Card
                                key={category.id}
                                className={
                                    category.is_active
                                        ? undefined
                                        : 'opacity-70'
                                }
                            >
                                <CardHeader>
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <div className="bg-muted rounded-lg p-2">
                                                <FolderTree className="size-5" />
                                            </div>
                                            <div className="min-w-0">
                                                <CardTitle className="truncate">
                                                    {category.name}
                                                </CardTitle>
                                                <CardDescription>
                                                    {category.children.length}{' '}
                                                    {category.children
                                                        .length === 1
                                                        ? 'subcategoria'
                                                        : 'subcategorias'}
                                                </CardDescription>
                                            </div>
                                            <Badge
                                                variant={
                                                    category.is_active
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {category.is_active
                                                    ? 'Ativa'
                                                    : 'Inativa'}
                                            </Badge>
                                        </div>
                                        <CategoryActions category={category} />
                                    </div>
                                </CardHeader>

                                {category.children.length > 0 && (
                                    <CardContent className="space-y-2">
                                        {category.children.map((child) => (
                                            <div
                                                key={child.id}
                                                className={`flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between ${
                                                    child.is_active
                                                        ? ''
                                                        : 'opacity-70'
                                                }`}
                                            >
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <span className="truncate text-sm font-medium">
                                                        {child.name}
                                                    </span>
                                                    <Badge
                                                        variant={
                                                            child.is_active
                                                                ? 'secondary'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {child.is_active
                                                            ? 'Ativa'
                                                            : 'Inativa'}
                                                    </Badge>
                                                </div>
                                                <CategoryActions
                                                    category={child}
                                                />
                                            </div>
                                        ))}
                                    </CardContent>
                                )}
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

CategoriesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Categorias',
            href: index(),
        },
    ],
};
