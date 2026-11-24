import { buildRestUrl, requestMagentoJson } from "../platform/magento";

export interface CustomerSectionMap {
    [section: string]: any;
}

export interface LoadOptions {
    forceReload?: boolean;
}

export interface CustomerSectionsConfig {
    loadUrl?: string;
}

export type SectionListener = (
    sectionName: string,
    data: any,
) => void;

export class CustomerSections {
    private readonly storageKey = 'mage-cache-storage';

    private readonly loadUrl: string;

    private listeners = new Map<string, Set<SectionListener>>();

    constructor(config: CustomerSectionsConfig = {}) {
        this.loadUrl =
            config.loadUrl ??
            buildRestUrl('/customer/section/load');

        this.watchStorage();
    }

    /**
     * Returns Magento customerData instance if available.
     */
    private getMagentoCustomerData(): any | null {
        const win = window as any;

        return (
            win.customerData ||
            win.checkoutConfig?.customerData ||
            null
        );
    }

    private getStorage(): CustomerSectionMap {
        try {
            const value = localStorage.getItem(
                this.storageKey,
            );

            if (!value) {
                return {};
            }

            return JSON.parse(value);
        } catch {
            return {};
        }
    }

    private saveStorage(
        sections: CustomerSectionMap,
    ): void {
        try {
            localStorage.setItem(
                this.storageKey,
                JSON.stringify(sections),
            );
        } catch {
            //
        }
    }

    get<T = any>(
        sectionName: string,
    ): T | null {
        const magentoCustomerData =
            this.getMagentoCustomerData();

        try {
            if (
                magentoCustomerData?.get &&
                typeof magentoCustomerData.get ===
                    'function'
            ) {
                const observable =
                    magentoCustomerData.get(
                        sectionName,
                    );

                if (
                    typeof observable ===
                    'function'
                ) {
                    return observable();
                }
            }
        } catch {
            //
        }

        const storage = this.getStorage();

        return (
            storage[sectionName] ?? null
        );
    }

    getMany(
        sectionNames: string[],
    ): CustomerSectionMap {
        return sectionNames.reduce(
            (result, name) => {
                result[name] = this.get(name);
                return result;
            },
            {} as CustomerSectionMap,
        );
    }

    async load(
        sectionNames: string[],
        options: LoadOptions = {},
    ): Promise<CustomerSectionMap> {
        const url = new URL(
            this.loadUrl,
            window.location.origin,
        );

        url.searchParams.set(
            'sections',
            sectionNames.join(','),
        );

        url.searchParams.set(
            'force_new_section_timestamp',
            options.forceReload
                ? 'true'
                : 'false',
        );

        const response = await requestMagentoJson(
            url.toString(),
            {
                headers: {
                    Accept:
                        'application/json',
                },
            },
        );

        if (!response.ok) {
            throw new Error(
                `Failed loading customer sections (${response.status})`,
            );
        }

        const sections =
            await response.json();

        this.merge(sections);

        return sections;
    }

    async reload(
        ...sectionNames: string[]
    ): Promise<CustomerSectionMap> {
        return this.load(sectionNames, {
            forceReload: true,
        });
    }

    merge(
        sections: CustomerSectionMap,
    ): void {
        const existing =
            this.getStorage();

        const updated = {
            ...existing,
            ...sections,
        };

        this.saveStorage(updated);

        Object.entries(sections).forEach(
            ([name, data]) => {
                this.emit(name, data);
            },
        );
    }

    set(
        sectionName: string,
        data: any,
    ): void {
        this.merge({
            data,
        });
    }

    invalidate(
        ...sectionNames: string[]
    ): void {
        const storage =
            this.getStorage();

        sectionNames.forEach((name) => {
            delete storage[name];
        });

        this.saveStorage(storage);
    }

    subscribe(
        sectionName: string,
        listener: SectionListener,
    ): () => void {
        if (
            !this.listeners.has(
                sectionName,
            )
        ) {
            this.listeners.set(
                sectionName,
                new Set(),
            );
        }

        this.listeners
            .get(sectionName)!
            .add(listener);

        listener(
            sectionName,
            this.get(sectionName),
        );

        return () => {
            this.listeners
                .get(sectionName)
                ?.delete(listener);
        };
    }

    private emit(
        sectionName: string,
        data: any,
    ): void {
        this.listeners
            .get(sectionName)
            ?.forEach((listener) => {
                listener(
                    sectionName,
                    data,
                );
            });

        window.dispatchEvent(
            new CustomEvent(
                'customer-section-updated',
                {
                    detail: {
                        sectionName,
                        data,
                    },
                },
            ),
        );
    }

    /**
     * Detect updates made by Magento KO/customerData.
     */
    private watchStorage(): void {
        window.addEventListener(
            'storage',
            () => {
                const storage =
                    this.getStorage();

                Object.entries(storage)
                    .forEach(
                        ([name, data]) => {
                            this.emit(
                                name,
                                data,
                            );
                        },
                    );
            },
        );
    }
}
