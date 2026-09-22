import { Form, Head, Link, usePage } from '@inertiajs/react';
import { FolderTree, Pencil, Plus, Power, Tags } from 'lucide-react';
import CategoryController from '@/actions/App/Http/Controllers/CategoryController';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { sortListing } from '@/lib/listing';
import { create, edit, index } from '@/routes/categories';
import type {
    Category,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    categories: Category[];
    filters: ListingQueryState;
    hasRecords: boolean;
    typeOptions: ListingFilterOption[];
    statusOptions: ListingFilterOption[];
};

const rowGridClass =
    'md:grid-cols-[minmax(0,1.8fr)_minmax(7rem,0.6fr)_minmax(7rem,0.6fr)_minmax(12rem,0.8fr)]';

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

function CategoryRow({
    category,
    nested = false,
}: {
    category: Category;
    nested?: boolean;
}) {
    return (
        <div
            className={`grid grid-cols-1 gap-3 px-4 py-3 md:items-center md:gap-3 ${rowGridClass} ${
                category.is_active ? '' : 'opacity-70'
            }`}
        >
            <div className="flex min-w-0 items-center gap-3">
                <div className="bg-muted rounded-lg p-2">
                    <FolderTree className="size-4" />
                </div>
                <p className={`truncate font-medium ${nested ? 'md:pl-4' : ''}`}>
                    {nested ? `↳ ${category.name}` : category.name}
                </p>
            </div>
            <Badge variant="outline" className="w-fit">
                {category.type_label}
            </Badge>
            <Badge
                variant={category.is_active ? 'secondary' : 'outline'}
                className="w-fit"
            >
                {category.is_active ? 'Ativa' : 'Inativa'}
            </Badge>
            <CategoryActions category={category} />
        </div>
    );
}

export default function CategoriesIndex() {
    const {
        categories,
        filters,
        hasRecords,
        typeOptions,
        statusOptions,
        workspace,
    } = usePage<Props>().props;
    const listUrl = index.url();
    const onSort = (column: string) => sortListing(listUrl, filters, column);

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

                {!hasRecords ? (
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
                    <>
                        <ListingToolbar
                            url={listUrl}
                            query={filters}
                            searchPlaceholder="Buscar categoria…"
                            selects={[
                                {
                                    key: 'type',
                                    label: 'Tipo',
                                    value: filters.type,
                                    options: typeOptions,
                                    allLabel: 'Todos',
                                },
                                {
                                    key: 'status',
                                    label: 'Situação',
                                    value: filters.status,
                                    options: statusOptions,
                                    allLabel: 'Todas',
                                },
                            ]}
                        />

                        {categories.length === 0 ? (
                            <ListingEmpty />
                        ) : (
                            <Card className="gap-0 overflow-hidden py-0">
                                <div
                                    className={`text-muted-foreground hidden gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid ${rowGridClass}`}
                                >
                                    <SortableColumn
                                        column="name"
                                        label="Categoria"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="type"
                                        label="Tipo"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="status"
                                        label="Situação"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <span className="text-right">Ações</span>
                                </div>
                                <div className="divide-y">
                                    {categories.map((category) => (
                                        <div key={category.id}>
                                            <CategoryRow category={category} />
                                            {category.children.map((child) => (
                                                <CategoryRow
                                                    key={child.id}
                                                    category={child}
                                                    nested
                                                />
                                            ))}
                                        </div>
                                    ))}
                                </div>
                            </Card>
                        )}
                    </>
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
