function isAbsoluteUrl(path) {
    return /^[a-z][a-z\d+\-.]*:/i.test(path) || path.startsWith('//');
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

export function url(path = '') {
    if (typeof path !== 'string' || path.trim() === '') {
        return getBaseUrl();
    }

    if (isAbsoluteUrl(path)) {
        return path;
    }

    const normalizedPath = path.replace(/^\/+/, '');

    return new URL(normalizedPath, getBaseUrl()).toString();
}
