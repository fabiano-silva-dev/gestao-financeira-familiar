export type CategoryType = 'income' | 'expense';

export type Category = {
    id: number;
    name: string;
    type: CategoryType;
    type_label: string;
    parent_id: number | null;
    is_active: boolean;
    has_children: boolean;
    children: Category[];
};

export type CategoryParentOption = {
    id: number;
    name: string;
    type: CategoryType;
    type_label: string;
    is_active: boolean;
};

export type CategoryTypeOption = {
    value: CategoryType;
    label: string;
};
