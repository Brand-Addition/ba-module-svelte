export const _ = (text) => {
    if (typeof window !== 'undefined' && typeof window.mageTranslate === 'function') {
        return window.mageTranslate(text);
    }

    return text;
};
