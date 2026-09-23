import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ListFilter, Pencil, Plus, Power } from 'lucide-react';
import ClassificationRuleController from '@/actions/App/Http/Controllers/ClassificationRuleController';
import { ListingEmpty } from '@/components/listing/listing-empty';
import { ListingToolbar } from '@/components/listing/listing-toolbar';
import { SortableColumn } from '@/components/listing/sortable-column';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { sortListing } from '@/lib/listing';
import { create, edit, index } from '@/routes/classification-rules';
import type {
    ClassificationRule,
    ClassificationRuleActionTypeOption,
    ClassificationRuleAutomationLevelOption,
    ClassificationRuleMatchTypeOption,
    ListingFilterOption,
    ListingQueryState,
} from '@/types';

type Props = {
    rules: ClassificationRule[];
    filters: ListingQueryState;
    hasRecords: boolean;
    matchTypeOptions: ClassificationRuleMatchTypeOption[];
    actionTypeOptions: ClassificationRuleActionTypeOption[];
    automationLevelOptions: ClassificationRuleAutomationLevelOption[];
    statusOptions: ListingFilterOption[];
};

const rowGridClass =
    'md:grid-cols-[minmax(0,1.25fr)_minmax(7rem,0.5fr)_minmax(10rem,0.75fr)_minmax(0,0.85fr)_minmax(7rem,0.5fr)_minmax(12rem,0.8fr)]';

function RuleActions({ rule }: { rule: ClassificationRule }) {
    return (
        <div className="flex flex-wrap justify-end gap-2">
            <Button variant="outline" size="sm" asChild>
                <Link href={edit(rule.id)}>
                    <Pencil />
                    Editar
                </Link>
            </Button>
            <Form
                {...ClassificationRuleController.toggleStatus.form(rule.id)}
                options={{ preserveScroll: true }}
            >
                {({ processing }) => (
                    <Button variant="ghost" size="sm" disabled={processing}>
                        <Power />
                        {rule.is_active ? 'Desativar' : 'Ativar'}
                    </Button>
                )}
            </Form>
        </div>
    );
}

export default function ClassificationRulesIndex() {
    const {
        rules,
        filters,
        hasRecords,
        matchTypeOptions,
        actionTypeOptions,
        automationLevelOptions,
        statusOptions,
        workspace,
    } = usePage<Props>().props;
    const listUrl = index.url();
    const onSort = (column: string) => sortListing(listUrl, filters, column);

    return (
        <>
            <Head title="Regras" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Regras de classificação
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Classifique automaticamente movimentos parecidos de{' '}
                            <span className="font-medium">
                                {workspace.current?.name}
                            </span>
                            .
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Nova regra
                        </Link>
                    </Button>
                </div>

                {!hasRecords ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <div className="bg-muted rounded-full p-3">
                                <ListFilter className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    Nenhuma regra cadastrada
                                </h2>
                                <p className="text-muted-foreground max-w-md text-sm">
                                    Crie regras pelo histórico da conciliação
                                    ou cadastre um trecho do extrato, como um
                                    nome no PIX.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>
                                    Cadastrar primeira regra
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <ListingToolbar
                            url={listUrl}
                            query={filters}
                            searchPlaceholder="Buscar regra, trecho ou empresa…"
                            selects={[
                                {
                                    key: 'action_type',
                                    label: 'Tipo',
                                    value: filters.action_type,
                                    options: actionTypeOptions,
                                    allLabel: 'Todos',
                                },
                                {
                                    key: 'automation_level',
                                    label: 'Automação',
                                    value: filters.automation_level,
                                    options: automationLevelOptions,
                                    allLabel: 'Todas',
                                },
                                {
                                    key: 'match_type',
                                    label: 'Correspondência',
                                    value: filters.match_type,
                                    options: matchTypeOptions,
                                    allLabel: 'Todas',
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

                        {rules.length === 0 ? (
                            <ListingEmpty />
                        ) : (
                            <Card className="gap-0 overflow-hidden py-0">
                                <div
                                    className={`text-muted-foreground hidden gap-3 border-b px-4 py-3 text-xs font-medium tracking-wide uppercase md:grid ${rowGridClass}`}
                                >
                                    <SortableColumn
                                        column="name"
                                        label="Regra"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="action_type"
                                        label="Tipo"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="automation_level"
                                        label="Automação"
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={onSort}
                                    />
                                    <SortableColumn
                                        column="match_type"
                                        label="Correspondência"
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
                                    {rules.map((rule) => (
                                        <div
                                            key={rule.id}
                                            className={`grid grid-cols-1 gap-3 px-4 py-3 md:items-center md:gap-3 ${rowGridClass} ${
                                                rule.is_active ? '' : 'opacity-70'
                                            }`}
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {rule.name}
                                                </p>
                                                <p className="text-muted-foreground mt-1 truncate text-xs">
                                                    {rule.pattern}
                                                    {rule.payee_name
                                                        ? ` · ${rule.payee_name}`
                                                        : ''}
                                                    {rule.category_name
                                                        ? ` · ${rule.category_name}`
                                                        : ''}
                                                    {rule.action_type ===
                                                    'transfer'
                                                        ? ` · ${rule.financial_account_name ?? 'Sem conta do extrato'}${
                                                              rule.counterpart_account_name
                                                                  ? ` → ${rule.counterpart_account_name}`
                                                                  : ''
                                                          }`
                                                        : ''}
                                                </p>
                                            </div>
                                            <Badge
                                                variant="outline"
                                                className="w-fit"
                                            >
                                                {rule.action_type_label}
                                            </Badge>
                                            <Badge
                                                variant="secondary"
                                                className="w-fit"
                                            >
                                                {rule.automation_level_label}
                                            </Badge>
                                            <Badge
                                                variant="outline"
                                                className="w-fit"
                                            >
                                                {rule.match_type_label}
                                            </Badge>
                                            <Badge
                                                variant={
                                                    rule.is_active
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                className="w-fit"
                                            >
                                                {rule.is_active
                                                    ? 'Ativa'
                                                    : 'Inativa'}
                                            </Badge>
                                            <RuleActions rule={rule} />
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

ClassificationRulesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Regras',
            href: index(),
        },
    ],
};
