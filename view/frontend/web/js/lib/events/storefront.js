const DEFAULT_BUBBLES = true;

function getJQuery() {
    if (typeof window === 'undefined' || typeof window.jQuery !== 'function') {
        return null;
    }

    return window.jQuery;
}

export const CUSTOMER_SECTIONS_UPDATED_EVENT = 'ba:svelte:customer-sections:updated';
export const STOREFRONT_MESSAGE_EVENT = 'ba:svelte:messages:message';
export const STOREFRONT_MESSAGES_UPDATED_EVENT = 'ba:svelte:messages:updated';
export const AJAX_ADD_TO_CART_EVENT = 'ajax:addToCart';
export const AJAX_ADD_TO_CART_ERROR_EVENT = 'ajax:addToCart:error';
export const CATALOG_ADD_TO_CART_REDIRECT_EVENT = 'catalogCategoryAddToCartRedirect';

export function dispatchStorefrontEvent(target, name, detail = {}, options = {}) {
    if (!target || typeof target.dispatchEvent !== 'function') {
        return null;
    }

    const {
        bubbles = DEFAULT_BUBBLES,
        cancelable = false,
        composed = false,
        jquery = false,
    } = options;

    const event = new CustomEvent(name, {
        bubbles,
        cancelable,
        composed,
        detail,
    });

    target.dispatchEvent(event);

    if (jquery) {
        const $ = getJQuery();
        if ($) {
            $(target).trigger(name, detail);
        }
    }

    return event;
}

export function dispatchCompatEvent(target, name, detail = {}, options = {}) {
    return dispatchStorefrontEvent(target, name, detail, {
        ...options,
        jquery: true,
    });
}

export function listenForStorefrontEvent(target, name, listener, options) {
    if (!target || typeof target.addEventListener !== 'function' || typeof listener !== 'function') {
        return () => {};
    }

    target.addEventListener(name, listener, options);

    return () => {
        if (typeof target.removeEventListener === 'function') {
            target.removeEventListener(name, listener, options);
        }
    };
}
