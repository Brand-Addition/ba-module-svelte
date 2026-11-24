export function serializeValue(value: any): string {
    if (value === null) {
        return 'null';
    }

    if (typeof value === 'string') {
        return JSON.stringify(value);
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    if (Array.isArray(value)) {
        return `[${value.map(serializeValue).join(', ')}]`;
    }

    if (typeof value === 'object') {
        return `{ ${Object.entries(value)
            .map(([key, val]) => `${key}: ${serializeValue(val)}`)
            .join(', ')} }`;
    }

    return JSON.stringify(value);
}