export type Category = {
    id: number;
    name: string;
    parent_id: number | null;
    is_active: boolean;
    has_children: boolean;
    children: Category[];
};

export type CategoryParentOption = {
    id: number;
    name: string;
    is_active: boolean;
};
