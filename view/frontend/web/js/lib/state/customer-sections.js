import { requestJson } from '../http.js';
import { url as buildMagentoUrl } from '../url.js';
import {
    CUSTOMER_SECTIONS_UPDATED_EVENT,
    dispatchStorefrontEvent,
} from '../events/storefront.js';

const STORAGE_KEY = 'mage-cache-storage';

function createWritableState(initialValue) {
    let value = initialValue;
    const subscribers = new Set();

    function notify() {
        subscribers.forEach((subscriber) => {
            subscriber(value);
        });
    }

    return {
        get() {
            return value;
        },
        set(nextValue) {
            value = nextValue;
            notify();
            return value;
        },
        subscribe(subscriber) {
            if (typeof subscriber !== 'function') {
                return () => {};
            }

            subscribers.add(subscriber);
            subscriber(value);

            return () => {
                subscribers.delete(subscriber);
            };
        },
        update(updater) {
            if (typeof updater !== 'function') {
                return value;
            }

            return this.set(updater(value));
        },
    };
}

export function normalizeSectionNames(sectionNames = []) {
    const normalizedSectionNames = Array.isArray(sectionNames)
        ? sectionNames
        : [sectionNames];

    return Array.from(new Set(
        normalizedSectionNames
            .map((sectionName) => String(sectionName || '').trim())
            .filter((sectionName) => sectionName !== '')
    ));
}

function getStorageSnapshot() {
    if (typeof window === 'undefined' || !window.localStorage) {
        return {};
    }

    try {
        const serializedSections = window.localStorage.getItem(STORAGE_KEY);
        if (typeof serializedSections !== 'string' || serializedSections.trim() === '') {
            return {};
        }

        const parsedSections = JSON.parse(serializedSections);

        return parsedSections && typeof parsedSections === 'object' ? parsedSections : {};
    } catch {
        return {};
    }
}

function persistStorageSnapshot(sections = {}) {
    if (typeof window === 'undefined' || !window.localStorage) {
        return;
    }

    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(sections));
    } catch {
        // Ignore storage quota/privacy failures and keep the helper fetch-first.
    }
}

export function syncCustomerSectionsCache(sections = {}, options = {}) {
    if (!sections || typeof sections !== 'object') {
        return {};
    }

    const {
        emit = true,
        sectionNames = Object.keys(sections),
        source = 'manual',
    } = options;

    const snapshot = {
        ...getStorageSnapshot(),
        ...sections,
    };

    persistStorageSnapshot(snapshot);

    if (emit && typeof window !== 'undefined') {
        dispatchStorefrontEvent(window, CUSTOMER_SECTIONS_UPDATED_EVENT, {
            sectionNames: normalizeSectionNames(sectionNames),
            sections,
            source,
        });
    }

    return sections;
}

function getResolvedSectionLoadUrl(sectionLoadUrl = '') {
    return typeof sectionLoadUrl === 'string' && sectionLoadUrl.trim() !== ''
        ? sectionLoadUrl
        : buildMagentoUrl('customer/section/load');
}

export function getCachedCustomerSections(sectionNames = []) {
    const normalizedSectionNames = normalizeSectionNames(sectionNames);
    if (normalizedSectionNames.length === 0) {
        return {};
    }

    const storedSections = getStorageSnapshot();

    return normalizedSectionNames.reduce((sections, sectionName) => {
        if (Object.prototype.hasOwnProperty.call(storedSections, sectionName)) {
            sections[sectionName] = storedSections[sectionName];
        }

        return sections;
    }, {});
}

export function getCachedCustomerSection(sectionName) {
    const normalizedSectionName = normalizeSectionNames(sectionName)[0] ?? '';
    if (normalizedSectionName === '') {
        return null;
    }

    const cachedSection = getCachedCustomerSections([normalizedSectionName])[normalizedSectionName];

    return cachedSection ?? null;
}

export async function loadCustomerSections(sectionNames = [], options = {}) {
    const normalizedSectionNames = normalizeSectionNames(sectionNames);
    if (normalizedSectionNames.length === 0) {
        return {};
    }

    const {
        errorMessage = 'Unable to load customer sections.',
        forceReload = false,
        sectionLoadUrl = '',
        useCache = true,
    } = options;

    const cachedSections = useCache && !forceReload
        ? getCachedCustomerSections(normalizedSectionNames)
        : {};

    if (Object.keys(cachedSections).length === normalizedSectionNames.length) {
        syncCustomerSectionsCache(cachedSections, {
            sectionNames: normalizedSectionNames,
            source: 'cache',
        });

        return cachedSections;
    }

    const payload = await requestJson(getResolvedSectionLoadUrl(sectionLoadUrl), {
        errorMessage,
        query: {
            force_new_section_timestamp: forceReload ? 'true' : 'false',
            sections: normalizedSectionNames.join(','),
        },
    });

    const resolvedSections = payload && typeof payload === 'object' ? payload : {};

    syncCustomerSectionsCache(resolvedSections, {
        sectionNames: normalizedSectionNames,
        source: 'server',
    });

    return resolvedSections;
}

export async function loadCustomerSection(sectionName, options = {}) {
    const normalizedSectionName = normalizeSectionNames(sectionName)[0] ?? '';
    if (normalizedSectionName === '') {
        return null;
    }

    const sections = await loadCustomerSections([normalizedSectionName], options);

    return sections[normalizedSectionName] ?? null;
}

export async function reloadCustomerSections(sectionNames = [], options = {}) {
    return loadCustomerSections(sectionNames, {
        ...options,
        forceReload: true,
        useCache: false,
    });
}

export async function reloadCustomerSection(sectionName, options = {}) {
    return loadCustomerSection(sectionName, {
        ...options,
        forceReload: true,
        useCache: false,
    });
}

export function createCustomerSectionStore(sectionName, options = {}) {
    const normalizedSectionName = normalizeSectionNames(sectionName)[0] ?? '';
    const store = createWritableState({
        data: getCachedCustomerSection(normalizedSectionName),
        error: null,
        isLoading: false,
        lastLoadedAt: 0,
        sectionName: normalizedSectionName,
        source: 'initial',
    });

    async function hydrate(forceReload = false) {
        if (normalizedSectionName === '') {
            return null;
        }

        store.update((state) => ({
            ...state,
            error: null,
            isLoading: true,
            source: forceReload ? 'reload' : 'hydrate',
        }));

        try {
            const data = await loadCustomerSection(normalizedSectionName, {
                ...options,
                forceReload,
                useCache: !forceReload,
            });

            store.set({
                data,
                error: null,
                isLoading: false,
                lastLoadedAt: Date.now(),
                sectionName: normalizedSectionName,
                source: forceReload ? 'server' : 'cache-or-server',
            });

            return data;
        } catch (error) {
            store.update((state) => ({
                ...state,
                error: error instanceof Error ? error.message : 'Unable to hydrate customer section.',
                isLoading: false,
                source: 'error',
            }));

            throw error;
        }
    }

    return {
        get: store.get,
        reload() {
            return hydrate(true);
        },
        sectionName: normalizedSectionName,
        subscribe: store.subscribe,
        sync(data, syncOptions = {}) {
            if (normalizedSectionName !== '' && syncOptions.persist !== false) {
                syncCustomerSectionsCache({
                    [normalizedSectionName]: data,
                }, {
                    emit: syncOptions.emit !== false,
                    sectionNames: [normalizedSectionName],
                    source: syncOptions.source ?? 'manual',
                });
            }

            return store.set({
                data,
                error: null,
                isLoading: false,
                lastLoadedAt: Date.now(),
                sectionName: normalizedSectionName,
                source: syncOptions.source ?? 'manual',
            });
        },
        update: store.update,
        hydrate,
    };
}

export { CUSTOMER_SECTIONS_UPDATED_EVENT };
