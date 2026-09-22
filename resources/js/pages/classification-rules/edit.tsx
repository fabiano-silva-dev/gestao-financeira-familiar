import { Head } from '@inertiajs/react';
import ClassificationRuleForm from '@/components/classification-rules/classification-rule-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index } from '@/routes/classification-rules';
import type {
    ClassificationRule,
    ClassificationRuleActionTypeOption,
    ClassificationRuleMatchTypeOption,
    ReconciliationAccountOption,
    ReconciliationCategoryOption,
} from '@/types';

export default function ClassificationRulesEdit({
    rule,
    matchTypeOptions,
    actionTypeOptions,
    categoryOptions,
    accountOptions,
}: {
    rule: ClassificationRule;
    matchTypeOptions: ClassificationRuleMatchTypeOption[];
    actionTypeOptions: ClassificationRuleActionTypeOption[];
    categoryOptions: ReconciliationCategoryOption[];
    accountOptions: ReconciliationAccountOption[];
}) {
    return (
        <>
            <Head title={`Editar ${rule.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar regra
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Atualize como a regra reconhece {rule.name}.
                    </p>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Dados da regra</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ClassificationRuleForm
                            rule={rule}
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

ClassificationRulesEdit.layout = {
    breadcrumbs: [
        { title: 'Regras', href: index() },
        { title: 'Editar regra', href: index() },
    ],
};
