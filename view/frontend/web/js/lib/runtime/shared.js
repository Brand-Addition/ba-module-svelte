export const COMPONENT_SELECTOR = '.svelte-root, [data-ba-svelte-root]';
export const DEFAULT_OPEN_CLASS = 'is-open';
export const DEFAULT_ACTIVE_CLASS = 'is-active';

let nextGeneratedId = 0;

export function getDocument(node) {
    if (node instanceof Document) {
        return node;
    }

    return node?.ownerDocument ?? document;
}

export function queryAll(scope, selector) {
    if (!scope || typeof selector !== 'string' || selector.trim() === '') {
        return [];
    }

    const elements = [];

    if (scope instanceof Element && scope.matches(selector)) {
        elements.push(scope);
    }

    if (scope instanceof Document || scope instanceof DocumentFragment || scope instanceof Element) {
        elements.push(...scope.querySelectorAll(selector));
    }

    return elements.filter((element, index) => {
        if (!(element instanceof Element) || elements.indexOf(element) !== index) {
            return false;
        }

        if (scope instanceof Document) {
            return scope.contains(element);
        }

        if (scope instanceof DocumentFragment || scope instanceof Element) {
            return scope === element || scope.contains(element);
        }

        return false;
    });
}

export function parseJsonAttribute(value, fallback = {}) {
    if (typeof value !== 'string' || value.trim() === '') {
        return fallback;
    }

    try {
        return JSON.parse(value);
    } catch (error) {
        console.error('[BA_Svelte] Unable to parse runtime JSON.', error);

        return fallback;
    }
}

export function parseBoolean(value, fallback = null) {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value !== 'string') {
        return fallback;
    }

    if (value === 'true') {
        return true;
    }

    if (value === 'false') {
        return false;
    }

    return fallback;
}

export function uniqueElements(elements) {
    return elements.filter((element, index) => elements.indexOf(element) === index);
}

export function emitEvent(element, name, detail = {}) {
    element.dispatchEvent(new CustomEvent(name, {
        bubbles: true,
        detail,
    }));
}

export function ensureElementId(element, prefix = 'ba-svelte-runtime') {
    if (typeof element.id === 'string' && element.id !== '') {
        return element.id;
    }

    nextGeneratedId += 1;
    element.id = `${prefix}-${nextGeneratedId}`;

    return element.id;
}

export function resolveElements(root, value, fallbackSelectors = []) {
    const documentRef = getDocument(root);

    if (Array.isArray(value)) {
        return uniqueElements(value.filter((element) => element instanceof HTMLElement));
    }

    if (value instanceof HTMLElement) {
        return [value];
    }

    if (typeof value === 'string' && value.trim() !== '') {
        return uniqueElements([
            ...root.querySelectorAll(value),
            ...documentRef.querySelectorAll(value),
        ].filter((element) => element instanceof HTMLElement));
    }

    const elements = fallbackSelectors.flatMap((selector) => Array.from(root.querySelectorAll(selector)));

    return uniqueElements(elements.filter((element) => element instanceof HTMLElement));
}

export function readElementBooleanAttribute(element, name) {
    if (!(element instanceof Element) || !element.hasAttribute(name)) {
        return null;
    }

    const value = element.getAttribute(name);

    if (value === '' || value === name) {
        return true;
    }

    return parseBoolean(value, true);
}

export function readElementStringAttribute(element, name) {
    if (!(element instanceof Element) || !element.hasAttribute(name)) {
        return '';
    }

    return String(element.getAttribute(name) ?? '').trim();
}

function readOptionsAttribute(element) {
    return parseJsonAttribute(element.getAttribute('options'), {});
}

function mergeStringAttributes(element, options, attributeMap) {
    Object.entries(attributeMap).forEach(([attributeName, optionName]) => {
        const value = readElementStringAttribute(element, attributeName);

        if (value !== '') {
            options[optionName] = value;
        }
    });
}

function mergeBooleanAttributes(element, options, attributeMap) {
    Object.entries(attributeMap).forEach(([attributeName, optionName]) => {
        const value = readElementBooleanAttribute(element, attributeName);

        if (typeof value === 'boolean') {
            options[optionName] = value;
        }
    });
}

export function readDisclosureOptions(element, defaults = {}) {
    const options = {
        ...defaults,
        ...readOptionsAttribute(element),
    };

    mergeStringAttributes(element, options, {
        'active-class': 'activeClass',
        'open-class': 'openClass',
    });

    mergeBooleanAttributes(element, options, {
        'close-on-escape': 'closeOnEscape',
        'close-on-outside-click': 'closeOnOutsideClick',
        open: 'initialOpen',
    });

    return options;
}

export function readAccordionOptions(element, defaults = {}) {
    const options = {
        ...defaults,
        ...readOptionsAttribute(element),
    };

    mergeStringAttributes(element, options, {
        'item-selector': 'itemSelector',
        variant: 'variant',
    });

    mergeBooleanAttributes(element, options, {
        'allow-multiple': 'allowMultiple',
    });

    return options;
}

export function readModalOptions(element, defaults = {}) {
    const options = {
        ...defaults,
        ...readOptionsAttribute(element),
    };

    mergeStringAttributes(element, options, {
        'open-class': 'openClass',
        trigger: 'trigger',
    });

    mergeBooleanAttributes(element, options, {
        'close-on-backdrop': 'closeOnBackdrop',
        open: 'initialOpen',
    });

    return options;
}

export function toDisclosureControllerName(type) {
    return `ba:svelte:${type}`;
}
