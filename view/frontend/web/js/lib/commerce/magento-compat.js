/**
 * 
 */
import {
    CATALOG_ADD_TO_CART_REDIRECT_EVENT,
    dispatchCompatEvent,
} from '../events/storefront.js';
import { sanitizeHtmlFragment } from '../security.js';

const redirectParameterHooks = new Set();

export function updateMagentoHtmlFragment(selector, html) {
    if (typeof document === 'undefined' || typeof selector !== 'string' || selector.trim() === '' || typeof html !== 'string') {
        return;
    }

    const target = document.querySelector(selector);
    if (!(target instanceof HTMLElement)) {
        return;
    }

    const sanitizedHtml = sanitizeHtmlFragment(html);

    if (selector === '[data-block="minicart"]') {
        target.outerHTML = sanitizedHtml;

        const replacement = document.querySelector(selector);
        if (replacement instanceof HTMLElement) {
            dispatchCompatEvent(replacement, 'contentUpdated');
        }

        return;
    }

    target.innerHTML = sanitizedHtml;
}

export function updateMagentoProductStatus(form, selector, statusText) {
    if (
        typeof document === 'undefined'
        || !(form instanceof HTMLElement)
        || typeof selector !== 'string'
        || selector.trim() === ''
        || typeof statusText !== 'string'
    ) {
        return;
    }

    const closestScope = form.closest('.product-item-info, .product-info-main, .product-add-form, .product-item');
    const scopedMatches = closestScope ? Array.from(closestScope.querySelectorAll(selector)) : [];
    const targets = scopedMatches.length > 0
        ? scopedMatches
        : Array.from(document.querySelectorAll(selector));

    targets.forEach((element) => {
        if (!(element instanceof HTMLElement)) {
            return;
        }

        element.classList.remove('available');
        element.classList.add('unavailable');

        const label = element.querySelector('span');
        if (label instanceof HTMLElement) {
            label.textContent = statusText;
        } else {
            element.textContent = statusText;
        }
    });
}

function appendRedirectParameters(backUrl, redirectParameters = []) {
    if (typeof window === 'undefined' || typeof backUrl !== 'string' || backUrl.trim() === '') {
        return '';
    }

    const resolvedBackUrl = backUrl.trim();
    if (!Array.isArray(redirectParameters) || redirectParameters.length === 0) {
        return resolvedBackUrl;
    }

    const normalizedCurrentUrl = window.location.href.split(/[?#]/)[0];
    const normalizedBackUrl = resolvedBackUrl.split(/[?#]/)[0];

    if (normalizedCurrentUrl !== normalizedBackUrl) {
        return resolvedBackUrl;
    }

    const [urlWithoutHash, hash = ''] = resolvedBackUrl.split('#');
    const separator = urlWithoutHash.includes('?') ? '&' : '?';
    const redirectQuery = redirectParameters.join('&');

    return `${urlWithoutHash}${separator}${redirectQuery}${hash ? `#${hash}` : ''}`;
}

function getCompatRedirectParameters(form) {
    const detail = {
        form,
        redirectParameters: [],
    };
    const target = typeof document !== 'undefined'
        ? document.body ?? document.documentElement
        : null;

    dispatchCompatEvent(target, CATALOG_ADD_TO_CART_REDIRECT_EVENT, detail);

    return detail.redirectParameters;
}

export function registerAddToCartRedirectParameters(callback) {
    if (typeof callback !== 'function') {
        return () => {};
    }

    redirectParameterHooks.add(callback);

    return () => {
        redirectParameterHooks.delete(callback);
    };
}

export function collectAddToCartRedirectParameters(form, redirectParameters = []) {
    const collectedParameters = [];

    redirectParameterHooks.forEach((callback) => {
        const nextParameters = callback({
            form,
            redirectParameters: [...redirectParameters, ...collectedParameters],
        });

        if (Array.isArray(nextParameters)) {
            collectedParameters.push(...nextParameters);
        } else if (typeof nextParameters === 'string' && nextParameters.trim() !== '') {
            collectedParameters.push(nextParameters.trim());
        }
    });

    return Array.from(new Set(
        [...redirectParameters, ...collectedParameters, ...getCompatRedirectParameters(form)]
            .map((parameter) => String(parameter || '').trim())
            .filter((parameter) => parameter !== '')
    ));
}

export function resolveAddToCartRedirectUrl(backUrl, options = {}) {
    return appendRedirectParameters(backUrl, collectAddToCartRedirectParameters(
        options.form,
        options.redirectParameters ?? []
    ));
}
