import { serializeValue } from './serializeValue.ts';

export function formatArgs(args: Record<string, any>): string {
    const entries = Object.entries(args)
        .filter(([, value]) => value !== undefined);

    if (!entries.length) {
        return '';
    }

    return `(${entries
        .map(([key, value]) => `${key}: ${serializeValue(value)}`)
        .join(', ')})`;
}
