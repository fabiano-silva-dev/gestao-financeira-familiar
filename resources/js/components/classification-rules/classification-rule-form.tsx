import { Form, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ClassificationRuleController from '@/actions/App/Http/Controllers/ClassificationRuleController';
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
import { classificationRuleMatches } from '@/lib/classification-rule';
import { index } from '@/routes/classification-rules';
import type {
    ClassificationRule,
    ClassificationRuleActionType,
    ClassificationRuleActionTypeOption,
    ClassificationRuleDraft,
    ClassificationRuleMatchType,
    ClassificationRuleMatchTypeOption,
    ReconciliationAccountOption,
    ReconciliationCategoryOption,
} from '@/types';

type Props = {
    rule?: ClassificationRule;
    draft?: ClassificationRuleDraft;
    matchTypeOptions: ClassificationRuleMatchTypeOption[];
    actionTypeOptions: ClassificationRuleActionTypeOption[];
    categoryOptions: ReconciliationCategoryOption[];
    accountOptions: ReconciliationAccountOption[];
};

export default function ClassificationRuleForm({
    rule,
    draft,
    matchTypeOptions,
    actionTypeOptions,
    categoryOptions,
    accountOptions,
}: Props) {
    const [name, setName] = useState(rule?.name ?? draft?.name ?? '');
    const [matchType, setMatchType] = useState<ClassificationRuleMatchType>(
        (rule?.match_type ??
            draft?.match_type ??
            'contains') as ClassificationRuleMatchType,
    );
    const [pattern, setPattern] = useState(
        rule?.pattern ?? draft?.pattern ?? '',
    );
    const [actionType, setActionType] = useState<ClassificationRuleActionType>(
        (rule?.action_type ??
            draft?.action_type ??
            'expense') as ClassificationRuleActionType,
    );
    const [payee, setPayee] = useState(
        rule?.payee_name ?? draft?.payee_name ?? '',
    );
    const isTransfer = actionType === 'transfer';
    const selectedHelp =
        matchTypeOptions.find((option) => option.value === matchType)?.help ??
        '';
    const parents = useMemo(
        () =>
            categoryOptions.filter(
                (category) =>
                    category.parent_id === null &&
                    category.type === actionType,
            ),
        [categoryOptions, actionType],
    );
    const initialCategory = categoryOptions.find(
        (category) =>
            category.id === (rule?.category_id ?? draft?.category_id),
    );
    const [selectedParentId, setSelectedParentId] = useState(() => {
        if (!initialCategory || actionType === 'transfer') {
            return '';
        }

        return String(initialCategory.parent_id ?? initialCategory.id);
    });
    const children = useMemo(
        () =>
            categoryOptions.filter(
                (category) =>
                    selectedParentId !== '' &&
                    category.parent_id === Number(selectedParentId),
            ),
        [categoryOptions, selectedParentId],
    );
    const [selectedSubId, setSelectedSubId] = useState(() =>
        initialCategory?.parent_id && actionType !== 'transfer'
            ? String(initialCategory.id)
            : '',
    );
    const [accountId, setAccountId] = useState(
        rule?.counterpart_account_id || draft?.counterpart_account_id
            ? String(
                  rule?.counterpart_account_id ??
                      draft?.counterpart_account_id,
              )
            : '',
    );
    const [example, setExample] = useState(draft?.source_description ?? '');
    const categoryId =
        selectedSubId !== ''
            ? selectedSubId
            : selectedParentId !== ''
              ? selectedParentId
              : '';
    const form = rule
        ? ClassificationRuleController.update.form(rule.id)
        : ClassificationRuleController.store.form();
    const previewMatches =
        example.trim() !== '' && pattern.trim() !== ''
            ? classificationRuleMatches(matchType, pattern, example)
            : null;

    const changeActionType = (value: string) => {
        const next = value as ClassificationRuleActionType;
        setActionType(next);

        if (next === 'transfer') {
            setSelectedParentId('');
            setSelectedSubId('');
            return;
        }

        setAccountId('');

        if (
            selectedParentId !== '' &&
            !categoryOptions.some(
                (category) =>
                    String(category.id) === selectedParentId &&
                    category.type === next,
            )
        ) {
            setSelectedParentId('');
            setSelectedSubId('');
        }
    };

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={!rule}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nome da regra</Label>
                            <Input
                                id="name"
                                name="name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                placeholder="Ex.: PIX Fabiano"
                                maxLength={120}
                                required
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="action_type">Tipo</Label>
                            <input
                                type="hidden"
                                name="action_type"
                                value={actionType}
                            />
                            <Select
                                value={actionType}
                                onValueChange={changeActionType}
                            >
                                <SelectTrigger
                                    id="action_type"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {actionTypeOptions.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                Despesa e receita usam categoria.
                                Transferência usa uma conta própria e não cria
                                receita nem despesa.
                            </p>
                            <InputError message={errors.action_type} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="match_type">
                                Forma de correspondência
                            </Label>
                            <input
                                type="hidden"
                                name="match_type"
                                value={matchType}
                            />
                            <Select
                                value={matchType}
                                onValueChange={(value) =>
                                    setMatchType(
                                        value as ClassificationRuleMatchType,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="match_type"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {matchTypeOptions.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                {selectedHelp}
                            </p>
                            <InputError message={errors.match_type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="pattern">Texto comparado</Label>
                            <Input
                                id="pattern"
                                name="pattern"
                                value={pattern}
                                onChange={(event) =>
                                    setPattern(event.target.value)
                                }
                                placeholder="Ex.: FABIANO CARVALHO DA SILVA"
                                maxLength={255}
                                required
                            />
                            <p className="text-muted-foreground text-xs">
                                Use um trecho do nome ou várias palavras, como
                                Mercado Pago Fabiano.
                            </p>
                            <InputError message={errors.pattern} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="example">
                            Testar com uma descrição
                        </Label>
                        <Input
                            id="example"
                            value={example}
                            onChange={(event) =>
                                setExample(event.target.value)
                            }
                            placeholder="Cole um movimento do extrato para conferir"
                        />
                        {previewMatches !== null && (
                            <p
                                className={
                                    previewMatches
                                        ? 'text-positive text-xs'
                                        : 'text-muted-foreground text-xs'
                                }
                            >
                                {previewMatches
                                    ? 'Esta descrição combinaria com a regra.'
                                    : 'Esta descrição ainda não combinaria com a regra.'}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="payee_name">
                                Empresa ou beneficiário
                            </Label>
                            <Input
                                id="payee_name"
                                name="payee_name"
                                value={payee}
                                onChange={(event) =>
                                    setPayee(event.target.value)
                                }
                                placeholder="Ex.: Fabiano Carvalho"
                                maxLength={160}
                            />
                            <InputError message={errors.payee_name} />
                        </div>

                        {isTransfer ? (
                            <div className="grid gap-2">
                                <Label htmlFor="counterpart_account_id">
                                    Conta
                                </Label>
                                <input
                                    type="hidden"
                                    name="counterpart_account_id"
                                    value={accountId}
                                />
                                <Select
                                    value={
                                        accountId === '' ? 'none' : accountId
                                    }
                                    onValueChange={(value) =>
                                        setAccountId(
                                            value === 'none' ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="counterpart_account_id"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Conta própria" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Selecionar conta
                                        </SelectItem>
                                        {accountOptions.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {account.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-muted-foreground text-xs">
                                    A outra ponta da transferência entre contas
                                    da família.
                                </p>
                                <InputError
                                    message={errors.counterpart_account_id}
                                />
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="category_id">Categoria</Label>
                                <input
                                    type="hidden"
                                    name="category_id"
                                    value={categoryId}
                                />
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <Select
                                        value={
                                            selectedParentId === ''
                                                ? 'none'
                                                : selectedParentId
                                        }
                                        onValueChange={(value) => {
                                            setSelectedParentId(
                                                value === 'none' ? '' : value,
                                            );
                                            setSelectedSubId('');
                                        }}
                                    >
                                        <SelectTrigger
                                            id="category_id"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Categoria" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                Sem categoria
                                            </SelectItem>
                                            {parents.map((category) => (
                                                <SelectItem
                                                    key={category.id}
                                                    value={String(category.id)}
                                                >
                                                    {category.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Select
                                        value={
                                            selectedSubId === ''
                                                ? 'none'
                                                : selectedSubId
                                        }
                                        disabled={children.length === 0}
                                        onValueChange={(value) =>
                                            setSelectedSubId(
                                                value === 'none' ? '' : value,
                                            )
                                        }
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Subcategoria" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                Nenhuma
                                            </SelectItem>
                                            {children.map((category) => (
                                                <SelectItem
                                                    key={category.id}
                                                    value={String(category.id)}
                                                >
                                                    {category.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    Só aparecem categorias do tipo escolhido.
                                </p>
                                <InputError message={errors.category_id} />
                            </div>
                        )}
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
                            {rule ? 'Salvar alterações' : 'Cadastrar regra'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
