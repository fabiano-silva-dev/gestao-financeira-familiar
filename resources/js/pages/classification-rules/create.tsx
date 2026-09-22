import { Head, Link } from '@inertiajs/react';
import { ListFilter } from 'lucide-react';
import ClassificationRuleForm from '@/components/classification-rules/classification-rule-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { create, edit, index } from '@/routes/classification-rules';
import type {
    ClassificationRuleActionTypeOption,
    ClassificationRuleDraft,
    ClassificationRuleMatchHint,
    ClassificationRuleMatchTypeOption,
    ReconciliationAccountOption,
    ReconciliationCategoryOption,
} from '@/types';

export default function ClassificationRulesCreate({
    draft,
    matchingRule,
    matchTypeOptions,
    actionTypeOptions,
    categoryOptions,
    accountOptions,
}: {
    draft: ClassificationRuleDraft;
    matchingRule: ClassificationRuleMatchHint | null;
    matchTypeOptions: ClassificationRuleMatchTypeOption[];
    actionTypeOptions: ClassificationRuleActionTypeOption[];
    categoryOptions: ReconciliationCategoryOption[];
    accountOptions: ReconciliationAccountOption[];
}) {
    return (
        <>
            <Head title="Nova regra" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Nova regra
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Defina se o movimento é despesa, receita ou
                        transferência, e a categoria ou a conta correspondente.
                    </p>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Dados da regra</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {matchingRule !== null && (
                            <div className="border-primary/20 bg-primary/5 rounded-lg border p-3 text-sm">
                                <p className="flex items-center gap-2 font-medium">
                                    <ListFilter className="text-primary size-4" />
                                    Esta descrição já tem uma regra
                                </p>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    A regra {matchingRule.name} já classifica
                                    descrições com {matchingRule.pattern}. Não é
                                    necessário cadastrar outra igual.{' '}
                                    <Link
                                        className="text-primary font-medium underline-offset-4 hover:underline"
                                        href={edit(matchingRule.id)}
                                    >
                                        Ver regra
                                    </Link>
                                </p>
                            </div>
                        )}
                        <ClassificationRuleForm
                            draft={draft}
                            matchTypeOptions={matchTypeOptions}
                            actionTypeOptions={actionTypeOptions}
                            categoryOptions={categoryOptions}
                            accountOptions={accountOptions}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ClassificationRulesCreate.layout = {
    breadcrumbs: [
        { title: 'Regras', href: index() },
        { title: 'Nova regra', href: create() },
    ],
};
