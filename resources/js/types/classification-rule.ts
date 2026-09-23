export type ClassificationRuleMatchType =
    | 'contains'
    | 'contains_all_words'
    | 'contains_any_word'
    | 'starts_with'
    | 'equals';

export type ClassificationRuleActionType = 'income' | 'expense' | 'transfer';

export type ClassificationRuleAutomationLevel =
    | 'classify_only'
    | 'reconcile_existing'
    | 'create_and_reconcile';

export type ClassificationRule = {
    id: number;
    name: string;
    match_type: ClassificationRuleMatchType;
    match_type_label: string;
    match_type_help: string;
    pattern: string;
    action_type: ClassificationRuleActionType;
    action_type_label: string;
    automation_level: ClassificationRuleAutomationLevel;
    automation_level_label: string;
    automation_level_help: string;
    payee_name: string | null;
    category_id: number | null;
    category_name: string | null;
    financial_account_id: number | null;
    financial_account_name: string | null;
    counterpart_account_id: number | null;
    counterpart_account_name: string | null;
    is_active: boolean;
};

export type ClassificationRuleDraft = {
    name: string;
    match_type: string;
    pattern: string;
    action_type: ClassificationRuleActionType | string;
    automation_level: ClassificationRuleAutomationLevel | string;
    payee_name: string;
    category_id: number | null;
    financial_account_id: number | null;
    counterpart_account_id: number | null;
    source_description: string;
};

export type ClassificationRuleMatchTypeOption = {
    value: ClassificationRuleMatchType;
    label: string;
    help: string;
};

export type ClassificationRuleActionTypeOption = {
    value: ClassificationRuleActionType;
    label: string;
};

export type ClassificationRuleAutomationLevelOption = {
    value: ClassificationRuleAutomationLevel;
    label: string;
    help: string;
};

export type ClassificationRulePrompt = {
    description: string;
    payee_name: string;
    action_type: ClassificationRuleActionType;
    category_id: number | null;
    financial_account_id: number | null;
    counterpart_account_id: number | null;
    return_to?: string;
};

export type ClassificationRuleMatchHint = {
    id: number;
    name: string;
    pattern: string;
    match_type: ClassificationRuleMatchType;
};
