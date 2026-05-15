import {
    DEFAULT_ACTIVE_CLASS,
    ensureElementId,
    getDocument,
    queryAll,
    readAccordionOptions,
} from './shared.js';

function getAccordionItems(element, selector = ':scope > ba-collapsible') {
    const matches = queryAll(element, selector).filter((item) => item.parentElement === element);

    if (matches.length > 0) {
        return matches;
    }

    return Array.from(element.children).filter((child) => child instanceof HTMLElement);
}

function isAccordionItemOpen(item) {
    if (typeof item.controller?.isOpen === 'function') {
        return item.controller.isOpen();
    }

    if (typeof item.isOpen === 'function') {
        return item.isOpen();
    }

    return item.hasAttribute('open');
}

function getAccordionDisclosure(item) {
    return Array.from(item.children).find((child) => child instanceof HTMLDetailsElement) ?? null;
}

function closeAccordionItem(item, reason) {
    if (typeof item.close === 'function') {
        item.close(reason);
    }

    if (!isAccordionItemOpen(item)) {
        return;
    }

    item.removeAttribute('open');

    const details = getAccordionDisclosure(item);

    if (details instanceof HTMLDetailsElement) {
        details.open = false;
    }
}

function openAccordionItem(item, reason) {
    if (typeof item.open === 'function') {
        item.open(reason);
    }

    if (isAccordionItemOpen(item)) {
        return;
    }

    item.setAttribute('open', '');

    const details = getAccordionDisclosure(item);

    if (details instanceof HTMLDetailsElement) {
        details.open = true;
    }
}

function normalizeAccordionState(items, { allowMultiple = false, requireOpen = false } = {}) {
    const openItems = items.filter(isAccordionItemOpen);

    if (allowMultiple) {
        if (requireOpen && openItems.length === 0 && items[0]) {
            openAccordionItem(items[0], 'accordion-init');
        }

        return;
    }

    if (openItems.length === 0) {
        if (requireOpen && items[0]) {
            openAccordionItem(items[0], 'accordion-init');
        }

        return;
    }

    openItems.slice(1).forEach((item) => {
        closeAccordionItem(item, 'accordion-init');
    });
}

function getAccordionSummary(item) {
    return Array.from(getAccordionDisclosure(item)?.children ?? []).find((child) => child instanceof HTMLElement && child.tagName === 'SUMMARY') ?? null;
}

function getAccordionPanel(item) {
    if (item?.__baAccordionPanel instanceof HTMLElement) {
        return item.__baAccordionPanel;
    }

    return Array.from(getAccordionDisclosure(item)?.children ?? []).find((child) => child instanceof HTMLElement && child.tagName !== 'SUMMARY') ?? null;
}

function ensureAccordionTabsStructure(element) {
    const documentRef = getDocument(element);
    let tabList = element.querySelector(':scope > [data-ba-accordion-tab-list]');
    let panels = element.querySelector(':scope > [data-ba-accordion-panels]');

    if (!(tabList instanceof HTMLElement)) {
        tabList = documentRef.createElement('div');
        tabList.dataset.baAccordionTabList = 'true';
        tabList.setAttribute('part', 'tab-list');
        element.prepend(tabList);
    }

    if (!(panels instanceof HTMLElement)) {
        panels = documentRef.createElement('div');
        panels.dataset.baAccordionPanels = 'true';
        panels.setAttribute('part', 'panels');

        if (tabList.nextSibling) {
            element.insertBefore(panels, tabList.nextSibling);
        } else {
            element.appendChild(panels);
        }
    }

    return { panels, tabList };
}

function syncAccordionTabButton(button, summary) {
    button.replaceChildren(...Array.from(summary.childNodes).map((node) => node.cloneNode(true)));
}

function ensureAccordionTabButton(item, summary, tabList) {
    let button = item.__baAccordionTabButton;

    if (!(button instanceof HTMLButtonElement)) {
        const documentRef = getDocument(item);

        button = documentRef.createElement('button');
        button.type = 'button';
        button.dataset.baAccordionTab = 'true';
        button.setAttribute('part', 'tab');
        item.__baAccordionTabButton = button;
    }

    syncAccordionTabButton(button, summary);

    if (button.parentElement !== tabList) {
        tabList.appendChild(button);
    }

    return button;
}

function syncAccordionTabListAccessibility(element, tabList) {
    const ariaLabel = element.getAttribute('aria-label');
    const ariaLabelledBy = element.getAttribute('aria-labelledby');
    const ariaDescribedBy = element.getAttribute('aria-describedby');
    const ariaOrientation = element.getAttribute('aria-orientation');

    tabList.setAttribute('role', 'tablist');
    tabList.setAttribute('aria-orientation', ariaOrientation === 'vertical' ? 'vertical' : 'horizontal');

    if (typeof ariaLabel === 'string' && ariaLabel.trim() !== '') {
        tabList.setAttribute('aria-label', ariaLabel);
    } else {
        tabList.removeAttribute('aria-label');
    }

    if (typeof ariaLabelledBy === 'string' && ariaLabelledBy.trim() !== '') {
        tabList.setAttribute('aria-labelledby', ariaLabelledBy);
    } else {
        tabList.removeAttribute('aria-labelledby');
    }

    if (typeof ariaDescribedBy === 'string' && ariaDescribedBy.trim() !== '') {
        tabList.setAttribute('aria-describedby', ariaDescribedBy);
    } else {
        tabList.removeAttribute('aria-describedby');
    }
}

function syncAccordionTabsState(element, items) {
    const { panels, tabList } = ensureAccordionTabsStructure(element);
    const activeButtons = new Set();
    const activePanels = new Set();

    element.dataset.baAccordionVariant = 'tabs';
    syncAccordionTabListAccessibility(element, tabList);

    items.forEach((item) => {
        const details = getAccordionDisclosure(item);
        const summary = getAccordionSummary(item);
        const panel = getAccordionPanel(item);

        if (!(details instanceof HTMLDetailsElement) || !(summary instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
            return;
        }

        const button = ensureAccordionTabButton(item, summary, tabList);
        const triggerId = ensureElementId(button, 'ba-svelte-accordion-trigger');
        const panelId = ensureElementId(panel, 'ba-svelte-accordion-panel');
        const open = details.open;

        details.hidden = true;
        details.setAttribute('aria-hidden', 'true');
        summary.removeAttribute('role');
        summary.removeAttribute('aria-selected');
        summary.removeAttribute('tabindex');

        button.classList.toggle(DEFAULT_ACTIVE_CLASS, open);
        button.setAttribute('role', 'tab');
        button.setAttribute('aria-controls', panelId);
        button.setAttribute('aria-selected', String(open));
        button.setAttribute('tabindex', open ? '0' : '-1');

        panel.hidden = !open;
        panel.dataset.baAccordionPanel = 'true';
        panel.setAttribute('aria-hidden', String(!open));
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', triggerId);
        panel.removeAttribute('tabindex');
        item.__baAccordionPanel = panel;

        if (panel.parentElement !== panels) {
            panels.appendChild(panel);
        }

        activeButtons.add(button);
        activePanels.add(panel);
    });

    Array.from(tabList.children).forEach((child) => {
        if (!activeButtons.has(child)) {
            child.remove();
        }
    });

    Array.from(panels.children).forEach((child) => {
        if (!activePanels.has(child)) {
            child.remove();
        }
    });
}

function syncAccordionDefaultState(element, items) {
    delete element.dataset.baAccordionVariant;

    const tabList = element.querySelector(':scope > [data-ba-accordion-tab-list]');
    const panels = element.querySelector(':scope > [data-ba-accordion-panels]');

    if (tabList instanceof HTMLElement) {
        tabList.removeAttribute('aria-describedby');
        tabList.removeAttribute('aria-label');
        tabList.removeAttribute('aria-labelledby');
        tabList.removeAttribute('aria-orientation');
        tabList.removeAttribute('role');
    }

    items.forEach((item) => {
        const details = getAccordionDisclosure(item);
        const summary = getAccordionSummary(item);
        const panel = getAccordionPanel(item);

        if (details instanceof HTMLDetailsElement) {
            details.hidden = false;
            details.removeAttribute('aria-hidden');
        }

        if (summary instanceof HTMLElement) {
            summary.removeAttribute('role');
            summary.removeAttribute('aria-selected');
            summary.removeAttribute('tabindex');
        }

        if (panel instanceof HTMLElement) {
            if (details instanceof HTMLDetailsElement && panel.parentElement !== details) {
                details.appendChild(panel);
            }

            panel.hidden = false;
            delete panel.dataset.baAccordionPanel;
            panel.removeAttribute('aria-hidden');
            panel.removeAttribute('role');
            panel.removeAttribute('aria-labelledby');
            panel.removeAttribute('tabindex');
        }

        item.__baAccordionTabButton?.remove?.();
        delete item.__baAccordionPanel;
        delete item.__baAccordionTabButton;
    });

    tabList?.remove?.();
    panels?.remove?.();
}

function syncAccordionVariantState(element, items, variant = '') {
    if (variant === 'tabs') {
        syncAccordionTabsState(element, items);
        return;
    }

    syncAccordionDefaultState(element, items);
}

function getAccordionTabButtonTarget(target) {
    return target instanceof Element
        ? target.closest('[data-ba-accordion-tab="true"]')
        : null;
}

function findAccordionItemByTabButton(items, button) {
    return items.find((item) => item.__baAccordionTabButton === button) ?? null;
}

export function createAccordionController(element) {
    if (!(element instanceof HTMLElement)) {
        return null;
    }

    const existingController = element.__baAccordionController;
    if (existingController) {
        return existingController;
    }

    const options = readAccordionOptions(element, {
        itemSelector: ':scope > ba-collapsible',
    });
    const variant = options.variant === 'tabs' ? 'tabs' : '';
    const requireOpen = variant === 'tabs';
    const allowMultiple = requireOpen ? false : (options.allowMultiple ?? false);
    const getItems = () => getAccordionItems(element, options.itemSelector);
    const activateItem = (targetItem, reason = 'accordion') => {
        getItems().forEach((item) => {
            if (item === targetItem) {
                openAccordionItem(item, reason);
            } else if (!allowMultiple) {
                closeAccordionItem(item, reason);
            }
        });

        syncState();
    };
    const syncState = () => {
        const items = getItems();

        normalizeAccordionState(items, { allowMultiple, requireOpen });
        syncAccordionVariantState(element, items, variant);
    };
    const handleToggle = (event) => {
        const details = event.target;
        const item = details?.parentElement;

        if (!(details instanceof HTMLDetailsElement) || details.dataset.baRuntimeDisclosure !== 'collapsible') {
            return;
        }

        if (!(item instanceof HTMLElement) || item.parentElement !== element) {
            return;
        }

        if (details.open && !allowMultiple) {
            getItems().forEach((candidate) => {
                if (candidate !== item) {
                    closeAccordionItem(candidate, 'accordion');
                }
            });
        }

        syncState();
    };
    const handleTabClick = (event) => {
        if (!requireOpen) {
            return;
        }

        const button = getAccordionTabButtonTarget(event.target);
        const item = findAccordionItemByTabButton(getItems(), button);

        if (!(button instanceof HTMLButtonElement) || !(item instanceof HTMLElement)) {
            return;
        }

        if (button.getAttribute('aria-selected') === 'true') {
            return;
        }

        activateItem(item, 'accordion-tab');
    };
    const handleTabKeydown = (event) => {
        if (!requireOpen) {
            return;
        }

        const currentButton = getAccordionTabButtonTarget(event.target);

        if (!(currentButton instanceof HTMLButtonElement)) {
            return;
        }

        const items = getItems();
        const buttons = items.map((item) => item.__baAccordionTabButton).filter((button) => button instanceof HTMLButtonElement);
        const currentIndex = buttons.indexOf(currentButton);

        if (currentIndex === -1) {
            return;
        }

        let targetIndex = currentIndex;

        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
            targetIndex = (currentIndex + 1) % buttons.length;
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
            targetIndex = (currentIndex - 1 + buttons.length) % buttons.length;
        } else if (event.key === 'Home') {
            targetIndex = 0;
        } else if (event.key === 'End') {
            targetIndex = buttons.length - 1;
        } else {
            return;
        }

        event.preventDefault();
        if (items[targetIndex]) {
            activateItem(items[targetIndex], 'accordion-keyboard');
        }
        buttons[targetIndex]?.focus();
    };

    const controller = {
        close(index) {
            closeAccordionItem(getItems()[index], 'accordion');
            queueMicrotask(syncState);
            return controller;
        },
        destroy() {
            element.removeEventListener('keydown', handleTabKeydown);
            element.removeEventListener('click', handleTabClick);
            element.removeEventListener('toggle', handleToggle, true);
            syncAccordionDefaultState(element, getItems());
            delete element.__baAccordionController;
        },
        items: getItems,
        open(index) {
            const item = getItems()[index];

            if (item) {
                activateItem(item, 'accordion');
            }

            return controller;
        },
        refresh() {
            syncState();
            return controller;
        },
        root: element,
        toggle(index) {
            getItems()[index]?.toggle?.('accordion');
            queueMicrotask(syncState);
            return controller;
        },
    };

    element.addEventListener('keydown', handleTabKeydown);
    element.addEventListener('click', handleTabClick);
    element.addEventListener('toggle', handleToggle, true);
    element.__baAccordionController = controller;
    queueMicrotask(() => {
        if (element.isConnected) {
            controller.refresh();
        }
    });

    return controller;
}
