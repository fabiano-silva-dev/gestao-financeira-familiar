import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
import { listingHasActiveFilters, visitListing } from '@/lib/listing';
import type {
    ListingDateFilter,
    ListingQueryState,
    ListingSelectFilter,
} from '@/types/listing';

type Props = {
    url: string;
    query: ListingQueryState;
    searchPlaceholder?: string;
    selects?: ListingSelectFilter[];
    dates?: ListingDateFilter[];
};

export function ListingToolbar({
    url,
    query,
    searchPlaceholder = 'Buscar…',
    selects = [],
    dates = [],
}: Props) {
    const queryRef = useRef(query);
    queryRef.current = query;
    const [search, setSearch] = useState(query.q);
    const filterKeys = [
        ...selects.map((select) => select.key),
        ...dates.map((date) => date.key),
    ];
    const hasFilters = listingHasActiveFilters(query, filterKeys);

    useEffect(() => {
        setSearch(query.q);
    }, [query.q]);

    useEffect(() => {
        if (search === queryRef.current.q) {
            return;
        }

        const timeout = window.setTimeout(() => {
            visitListing(url, queryRef.current, { q: search });
        }, 350);

        return () => window.clearTimeout(timeout);
    }, [search, url]);

    const update = (key: string, value: string) => {
        visitListing(url, query, {
            [key]: value === 'all' ? null : value,
        });
    };

    const clear = () => {
        const patch: Partial<ListingQueryState> = { q: '' };

        for (const key of filterKeys) {
            patch[key] = null;
        }

        setSearch('');
        visitListing(url, query, patch);
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="grid min-w-0 gap-1.5">
                <Label htmlFor="listing-search" className="sr-only">
                    Buscar
                </Label>
                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                    <Input
                        id="listing-search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={searchPlaceholder}
                        className="pl-9"
                    />
                </div>
            </div>

            {(selects.length > 0 || dates.length > 0 || hasFilters) && (
                <div className="flex flex-wrap items-end gap-3">
                    {selects.map((select) => (
                        <div key={select.key} className="grid min-w-40 flex-1 gap-1.5 sm:max-w-56">
                            <Label htmlFor={`listing-filter-${select.key}`}>
                                {select.label}
                            </Label>
                            <Select
                                value={select.value || 'all'}
                                onValueChange={(value) => update(select.key, value)}
                            >
                                <SelectTrigger
                                    id={`listing-filter-${select.key}`}
                                    className="w-full"
                                >
                                    <SelectValue placeholder={select.label} />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        {select.allLabel ?? 'Todos'}
                                    </SelectItem>
                                    {select.options.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    ))}

                    {dates.map((date) => (
                        <div key={date.key} className="grid min-w-40 flex-1 gap-1.5 sm:max-w-56">
                            <Label htmlFor={`listing-date-${date.key}`}>
                                {date.label}
                            </Label>
                            <Input
                                id={`listing-date-${date.key}`}
                                type={date.type ?? 'date'}
                                value={date.value ?? ''}
                                onChange={(event) =>
                                    update(date.key, event.target.value)
                                }
                            />
                        </div>
                    ))}

                    {hasFilters && (
                        <Button type="button" variant="ghost" onClick={clear}>
                            <X />
                            Limpar
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
