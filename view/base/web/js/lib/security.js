const UNSAFE_ELEMENT_SELECTOR = 'base, iframe, link, meta, object, script';
const UNSAFE_URL_ATTRIBUTES = new Set(['action', 'formaction', 'href', 'src', 'srcdoc', 'xlink:href']);

function normalizeUrlValue(value) {
    return String(value ?? '')
        .replace(/[\u0000-\u0020]+/g, '')
        .trim()
        .toLowerCase();
}

function isUnsafeUrlValue(value) {
    const normalizedValue = normalizeUrlValue(value);

    return normalizedValue.startsWith('javascript:')
        || normalizedValue.startsWith('vbscript:')
        || normalizedValue.startsWith('data:text/html')
        || normalizedValue.startsWith('data:application/xhtml+xml');
}

function sanitizeElementAttributes(element) {
    Array.from(element.attributes).forEach((attribute) => {
        const attributeName = attribute.name.toLowerCase();

        if (attributeName.startsWith('on')) {
            element.removeAttribute(attribute.name);
            return;
        }

        if (UNSAFE_URL_ATTRIBUTES.has(attributeName) && isUnsafeUrlValue(attribute.value)) {
            element.removeAttribute(attribute.name);
        }
    });
}

function sanitizeHtmlWithDomParser(html) {
    const parser = new DOMParser();
    const documentFragment = parser.parseFromString(`<body>${html}</body>`, 'text/html');
    const root = documentFragment.body;

    root.querySelectorAll(UNSAFE_ELEMENT_SELECTOR).forEach((element) => {
        element.remove();
    });

    root.querySelectorAll('*').forEach((element) => {
        sanitizeElementAttributes(element);
    });

    return root.innerHTML;
}

function sanitizeHtmlWithFallback(html) {
    return html
        .replace(/<\s*(script|iframe|object|embed|base|meta|link)\b[^>]*>([\s\S]*?)<\s*\/\s*\1\s*>/gi, '')
        .replace(/<\s*(script|iframe|object|embed|base|meta|link)\b[^>]*\/?\s*>/gi, '')
        .replace(/\s+on[a-z-]+\s*=\s*(".*?"|'.*?'|[^\s>]+)/gi, '')
        .replace(
            /\s+(action|formaction|href|src|srcdoc|xlink:href)\s*=\s*(".*?"|'.*?'|[^\s>]+)/gi,
            (match, attributeName, rawValue) => {
                const quote = rawValue[0];
                const hasWrappingQuotes = quote === '"' || quote === "'";
                const attributeValue = hasWrappingQuotes ? rawValue.slice(1, -1) : rawValue;

                return isUnsafeUrlValue(attributeValue)
                    ? ''
                    : ` ${attributeName}=${rawValue}`;
            }
        );
}

export function sanitizeHtmlFragment(html) {
    if (typeof html !== 'string') {
        return '';
    }

    if (typeof DOMParser === 'function') {
        return sanitizeHtmlWithDomParser(html);
    }

    return sanitizeHtmlWithFallback(html);
}

export function resolveSafeRedirectUrl(url, currentLocation = null) {
    if (typeof url !== 'string' || url.trim() === '') {
        return '';
    }

    const baseUrl = typeof currentLocation?.href === 'string' && currentLocation.href !== ''
        ? currentLocation.href
        : (typeof document !== 'undefined' && typeof document.baseURI === 'string' && document.baseURI !== ''
            ? document.baseURI
            : 'http://localhost/');

    let resolvedUrl;
    try {
        resolvedUrl = new URL(url.trim(), baseUrl);
    } catch {
        return '';
    }

    const protocol = resolvedUrl.protocol.toLowerCase();
    if (protocol !== 'http:' && protocol !== 'https:') {
        return '';
    }

    let currentOrigin = '';
    try {
        currentOrigin = new URL(baseUrl).origin;
    } catch {
        currentOrigin = '';
    }

    if (currentOrigin !== '' && resolvedUrl.origin !== currentOrigin) {
        return '';
    }

    return resolvedUrl.toString();
}
