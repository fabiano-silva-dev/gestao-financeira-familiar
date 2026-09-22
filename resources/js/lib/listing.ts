import { router } from '@inertiajs/react';
import type {
    ListingQueryState,
    ListingSortDirection,
} from '@/types/listing';

export function sortListing(
    url: string,
    query: ListingQueryState,
    column: string,
    firstDirection: ListingSortDirection = 'asc',
): void {
    visitListing(url, query, {
        sort: column,
        direction: nextSortDirection(
            query.sort,
            query.direction,
            column,
            firstDirection,
        ),
    });
}

export function listingParams(
    query: ListingQueryState,
    patch: Partial<ListingQueryState> = {},
): Record<string, string> {
    const next = { ...query, ...patch };
    const params: Record<string, string> = {};

    for (const [key, value] of Object.entries(next)) {
        if (value === null || value === undefined) {
            continue;
        }

        const stringValue = String(value).trim();

        if (stringValue === '' || stringValue === 'all') {
            continue;
        }

        params[key] = stringValue;
    }

    return params;
}

export function listingUrl(
    url: string,
    query: ListingQueryState,
    patch: Partial<ListingQueryState> = {},
): string {
    const params = new URLSearchParams(listingParams(query, patch)).toString();

    if (params === '') {
        return url;
    }

    return `${url}${url.includes('?') ? '&' : '?'}${params}`;
}

export function visitListing(
    url: string,
    query: ListingQueryState,
    patch: Partial<ListingQueryState> = {},
): void {
    router.get(url, listingParams(query, patch), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

export function nextSortDirection(
    currentSort: string,
    currentDirection: ListingSortDirection,
    column: string,
    firstDirection: ListingSortDirection = 'asc',
): ListingSortDirection {
    if (currentSort !== column) {
        return firstDirection;
    }

    return currentDirection === 'asc' ? 'desc' : 'asc';
}

export function listingHasActiveFilters(
    query: ListingQueryState,
    keys: string[] = [],
): boolean {
    if (query.q.trim() !== '') {
        return true;
    }

    return keys.some((key) => {
        const value = query[key];

        return value !== null && value !== undefined && value !== '' && value !== 'all';
    });
}
