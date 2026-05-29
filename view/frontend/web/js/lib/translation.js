const hasOwn = Object.prototype.hasOwnProperty;

function resolveTarget(target = typeof window !== 'undefined' ? window : globalThis) {
    return target && typeof target === 'object' ? target : null;
}

function ensureTranslationMap(target) {
    if (!target || typeof target !== 'object') {
        return Object.create(null);
    }

    if (!target.__baSvelteTranslations || typeof target.__baSvelteTranslations !== 'object') {
        target.__baSvelteTranslations = Object.create(null);
    }

    return target.__baSvelteTranslations;
}

export function registerSvelteTranslations(translations, target = resolveTarget()) {
    const resolvedTarget = resolveTarget(target);
    const nextTranslationMap = Object.create(null);

    if (translations && typeof translations === 'object') {
        Object.keys(translations).forEach((phrase) => {
            if (typeof translations[phrase] === 'string') {
                nextTranslationMap[phrase] = translations[phrase];
            }
        });
    }

    if (!resolvedTarget) {
        return nextTranslationMap;
    }

    resolvedTarget.__baSvelteTranslations = nextTranslationMap;

    return nextTranslationMap;
}

export function translateWithBaRuntime(text, target = resolveTarget()) {
    if (typeof text !== 'string' || text === '') {
        return text;
    }

    const resolvedTarget = resolveTarget(target);
    if (!resolvedTarget) {
        return text;
    }

    const translationMap = ensureTranslationMap(resolvedTarget);

    return hasOwn.call(translationMap, text) ? translationMap[text] : text;
}

export function ensureBaTranslate(target = resolveTarget()) {
    const resolvedTarget = resolveTarget(target);
    if (!resolvedTarget) {
        return null;
    }

    if (typeof resolvedTarget.baTranslate !== 'function') {
        resolvedTarget.baTranslate = (text) => translateWithBaRuntime(text, resolvedTarget);
    }

    return resolvedTarget.baTranslate;
}
