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

    return typeof body === 'object';
}

function normalizeBaseUrl(baseUrl) {
    if (typeof baseUrl !== 'string' || baseUrl.trim() === '') {
        return '/';
    }

    return baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`;
}

function getBaseUrl() {
    if (typeof window !== 'undefined' && typeof window.BASE_URL === 'string' && window.BASE_URL.trim() !== '') {
        return normalizeBaseUrl(window.BASE_URL);
    }

    if (typeof document !== 'undefined' && typeof document.baseURI === 'string' && document.baseURI.trim() !== '') {
        return normalizeBaseUrl(document.baseURI);
    }

    if (typeof window !== 'undefined' && window.location?.origin) {
        return `${window.location.origin}/`;
    }

    return '/';
}

function getRequestBaseUrl() {
    if (typeof window !== 'undefined' && typeof window.location?.origin === 'string' && window.location.origin !== '') {
        return `${window.location.origin}/`;
    }

    if (typeof document !== 'undefined' && typeof document.baseURI === 'string' && document.baseURI.trim() !== '') {
        return document.baseURI;
    }

    return 'http://localhost/';
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

function buildRequestUrl(url, params = {}) {
    const resolvedUrl = new URL(url, getRequestBaseUrl());

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
    } catch {
        return null;
    }
}

function resolveStoreCode(storeCode = '') {
    if (typeof storeCode === 'string' && storeCode.trim() !== '') {
        return storeCode.trim();
    }

    const svelteStoreCode = window?.__baStoreCode;
    if (typeof svelteStoreCode === 'string' && svelteStoreCode.trim() !== '') {
        return svelteStoreCode.trim();
    }

    const checkoutStoreCode = window?.checkoutConfig?.storeCode;

    return typeof checkoutStoreCode === 'string' && checkoutStoreCode.trim() !== ''
        ? checkoutStoreCode.trim()
        : 'default';
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

export function buildStorefrontUrl(path = '') {
    if (typeof path !== 'string' || path.trim() === '') {
        return getBaseUrl();
    }

    if (/^[a-z][a-z\d+\-.]*:/i.test(path) || path.startsWith('//')) {
        return path;
    }

    return new URL(path.replace(/^\/+/, ''), getBaseUrl()).toString();
}

export function buildRestUrl(path = '', options = {}) {
    const normalizedPath = typeof path === 'string' ? path.trim().replace(/^\/+/, '') : '';
    const storeCode = resolveStoreCode(options.storeCode);

    return buildStorefrontUrl(`rest/${storeCode}/V1/${normalizedPath}`);
}

export async function requestMagentoJson(url, options = {}) {
    const {
        body = null,
        credentials = 'same-origin',
        errorMessage = 'Request failed.',
        headers = {},
        method = 'GET',
        query = {},
        resolveErrorMessage = null,
        validateResponse = null,
    } = options;

    if (typeof fetch !== 'function') {
        throw new JsonRequestError('Fetch API is unavailable.');
    }

    const response = await fetch(buildRequestUrl(url, query), {
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
