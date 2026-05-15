export const _ = (text, ...args) => {
    if (typeof window !== 'undefined' && typeof window.mageTranslate === 'function') {
        text = window.mageTranslate(text);
    }

    return args.reduce((translatedText, arg) => translatedText.replace('%s', arg), text);
};
