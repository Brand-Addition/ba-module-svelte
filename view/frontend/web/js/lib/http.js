function isSerializableJsonBody(body) {
    if (body === null || body === undefined) {
        return false;
    }

    if (typeof FormData !== 'undefined' && body instanceof FormData) {
        return false;
    }

    if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
        return false;
    }

    if (typeof Blob !== 'undefined' && body instanceof Blob) {
        return false;
    }

    if (typeof ArrayBuffer !== 'undefined' && body instanceof ArrayBuffer) {
        return false;
    }

    return typeof body === 'object' || Array.isArray(body);
}

function createHeaders(headers = {}, body = null) {
    const resolvedHeaders = new Headers(headers);

    if (!resolvedHeaders.has('Accept')) {
        resolvedHeaders.set('Accept', 'application/json');
    }

    if (!resolvedHeaders.has('X-Requested-With')) {
        resolvedHeaders.set('X-Requested-With', 'XMLHttpRequest');
    }

    if (isSerializableJsonBody(body) && !resolvedHeaders.has('Content-Type')) {
        resolvedHeaders.set('Content-Type', 'application/json');
    }

    return resolvedHeaders;
}

function serializeBody(body) {
    if (isSerializableJsonBody(body)) {
        return JSON.stringify(body);
    }

    return body;
}

async function parseJsonResponse(response) {
    if (response.status === 204) {
        return null;
    }

    const responseText = await response.text();
    if (responseText.trim() === '') {
        return null;
    }

    try {
        return JSON.parse(responseText);
    } catch (error) {
        return null;
    }
}

export class JsonRequestError extends Error
{
    constructor(message, { status = 0, payload = null } = {}) {
        super(message);
        this.name = 'JsonRequestError';
        this.status = status;
        this.payload = payload;
    }
}

export function buildUrl(url, params = {}) {
    const resolvedUrl = new URL(url, window.location.origin);

    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') {
            return;
        }

        if (Array.isArray(value) || (typeof value === 'object' && value !== null)) {
            resolvedUrl.searchParams.set(key, JSON.stringify(value));
            return;
        }

        resolvedUrl.searchParams.set(key, String(value));
    });

    return resolvedUrl.toString();
}

export async function requestJson(url, options = {}) {
    const {
        body = null,
        credentials = 'same-origin',
        errorMessage = 'Request failed.',
        headers = {},
        method = 'GET',
        query = {},
        validateResponse = null,
        resolveErrorMessage = null,
    } = options;

    const response = await fetch(buildUrl(url, query), {
        body: serializeBody(body),
        credentials,
        headers: createHeaders(headers, body),
        method,
    });

    const payload = await parseJsonResponse(response);
    const isValid = typeof validateResponse === 'function'
        ? validateResponse(payload, response)
        : response.ok;

    if (!isValid) {
        const resolvedErrorMessage = typeof resolveErrorMessage === 'function'
            ? resolveErrorMessage(payload, response)
            : null;

        throw new JsonRequestError(
            resolvedErrorMessage || payload?.message || errorMessage,
            {
                status: response.status,
                payload,
            }
        );
    }

    return payload;
}
