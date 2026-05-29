export const _ = (text, ...args) => {
    if (typeof window !== 'undefined' && typeof window.baTranslate === 'function') {
        text = window.baTranslate(text);
    }

    return args.reduce((translatedText, arg) => translatedText.replace('%s', arg), text);
};
