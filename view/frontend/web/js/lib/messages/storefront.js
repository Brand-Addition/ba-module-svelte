import { sanitizeHtmlFragment } from '../security.js';

export const DEFAULT_MESSAGES_SELECTOR = '[data-placeholder="messages"]';
export const STORE_MESSAGE_TYPES = Object.freeze({
    error: 'error',
    info: 'info',
    success: 'success',
});

function normalizeMessage(type, text) {
    return {
        text: sanitizeHtmlFragment(String(text ?? '').trim()),
        type: String(type ?? STORE_MESSAGE_TYPES.info).trim() || STORE_MESSAGE_TYPES.info,
    };
}

export function dispatchStorefrontMessage(type, text) {
    const message = normalizeMessage(type, text);
    console.log('dispatchStorefrontMessage', message);
    window.dispatchEvent(new CustomEvent('svelte:message', {
        detail: message
    }));
}
