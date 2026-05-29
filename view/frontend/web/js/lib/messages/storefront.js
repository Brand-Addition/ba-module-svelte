import {
    STOREFRONT_MESSAGE_EVENT,
    STOREFRONT_MESSAGES_UPDATED_EVENT,
    dispatchStorefrontEvent,
} from '../events/storefront.js';
import { sanitizeHtmlFragment } from '../security.js';

export const DEFAULT_MESSAGES_SELECTOR = '[data-placeholder="messages"]';
export const STORE_MESSAGE_TYPES = Object.freeze({
    error: 'error',
    info: 'info',
    success: 'success',
});

function normalizeMessage(message, options = {}) {
    if (message && typeof message === 'object' && !Array.isArray(message)) {
        return {
            text: String(message.text ?? message.message ?? '').trim(),
            type: String(message.type ?? options.type ?? STORE_MESSAGE_TYPES.info).trim() || STORE_MESSAGE_TYPES.info,
        };
    }

    return {
        text: String(message ?? '').trim(),
        type: String(options.type ?? STORE_MESSAGE_TYPES.info).trim() || STORE_MESSAGE_TYPES.info,
    };
}

export function dispatchStorefrontMessage(message, options = {}) {
    const normalizedMessage = normalizeMessage(message, options);
    if (normalizedMessage.text === '') {
        return null;
    }

    const target = options.target
        ?? (typeof document !== 'undefined' ? document.body ?? document.documentElement : null);

    return dispatchStorefrontEvent(target, STOREFRONT_MESSAGE_EVENT, normalizedMessage);
}

export function updateMessageFragment(html, options = {}) {
    if (typeof html !== 'string') {
        return null;
    }

    if (typeof document === 'undefined') {
        return null;
    }

    const selector = typeof options.selector === 'string' && options.selector.trim() !== ''
        ? options.selector
        : DEFAULT_MESSAGES_SELECTOR;
    const target = document.querySelector(selector);

    if (!(target instanceof HTMLElement)) {
        return null;
    }

    target.innerHTML = sanitizeHtmlFragment(html);

    dispatchStorefrontEvent(target, STOREFRONT_MESSAGES_UPDATED_EVENT, {
        html,
        selector,
    });

    return target;
}

export function applyMessagePayload(payload, options = {}) {
    if (!payload) {
        return {
            dispatched: 0,
            fragmentTarget: null,
        };
    }

    if (typeof payload === 'string') {
        return {
            dispatched: dispatchStorefrontMessage(payload, options) ? 1 : 0,
            fragmentTarget: null,
        };
    }

    let dispatched = 0;

    if (Array.isArray(payload.messages)) {
        payload.messages.forEach((message) => {
            if (dispatchStorefrontMessage(message, options)) {
                dispatched += 1;
            }
        });
    } else if (typeof payload.message === 'string' && payload.message.trim() !== '') {
        if (dispatchStorefrontMessage(payload.message, options)) {
            dispatched += 1;
        }
    }

    const fragmentTarget = typeof payload.messages === 'string'
        ? updateMessageFragment(payload.messages, options)
        : null;

    return {
        dispatched,
        fragmentTarget,
    };
}
