import type {
    ClassificationRuleMatchType,
    ClassificationRulePrompt,
} from '@/types';

export function normalizeClassificationText(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim()
        .replace(/\s+/g, ' ');
}

export function classificationRuleMatches(
    type: ClassificationRuleMatchType | string,
    pattern: string,
    description: string,
): boolean {
    const normalizedDescription = normalizeClassificationText(description);
    const normalizedPattern = normalizeClassificationText(pattern);

    if (normalizedDescription === '' || normalizedPattern === '') {
        return false;
    }

    const descriptionWords = ` ${normalizedDescription} `;
    const words = normalizedPattern.split(' ').filter((word) => word !== '');

    switch (type) {
        case 'equals':
            return normalizedDescription === normalizedPattern;
        case 'starts_with':
            return normalizedDescription.startsWith(normalizedPattern);
        case 'contains':
            return normalizedDescription.includes(normalizedPattern);
        case 'contains_all_words':
            return words.every((word) =>
                descriptionWords.includes(` ${word} `),
            );
        case 'contains_any_word':
            return words.some((word) =>
                descriptionWords.includes(` ${word} `),
            );
        default:
            return false;
    }
}

export function classificationRuleCreateQuery(
    prompt: ClassificationRulePrompt,
): string {
    const params = new URLSearchParams();

    if (prompt.description !== '') {
        params.set('description', prompt.description);
    }

    if (prompt.action_type !== '') {
        params.set('action_type', prompt.action_type);
    }

    if (prompt.payee_name !== '') {
        params.set('payee_name', prompt.payee_name);
    }

    if (prompt.category_id !== null) {
        params.set('category_id', String(prompt.category_id));
    }

    if (prompt.counterpart_account_id !== null) {
        params.set('counterpart_account_id', String(prompt.counterpart_account_id));
    }

    const query = params.toString();

    return query === '' ? '' : `?${query}`;
}
