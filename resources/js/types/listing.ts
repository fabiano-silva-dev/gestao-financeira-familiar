export type ListingSortDirection = 'asc' | 'desc';

export type ListingQueryState = {
    q: string;
    sort: string;
    direction: ListingSortDirection;
} & Record<string, string | null | undefined>;

export type ListingFilterOption = {
    value: string;
    label: string;
};

export type ListingSelectFilter = {
    key: string;
    label: string;
    value?: string | null;
    options: ListingFilterOption[];
    allLabel?: string;
};

export type ListingDateFilter = {
    key: string;
    label: string;
    value?: string | null;
    type?: 'date' | 'month';
};
