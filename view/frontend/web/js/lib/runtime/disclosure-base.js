import {
    DEFAULT_ACTIVE_CLASS,
    DEFAULT_OPEN_CLASS,
    emitEvent,
    ensureElementId,
    getDocument,
    readDisclosureOptions,
    toDisclosureControllerName,
} from './shared.js';

function resolveDisclosureOpen(element, details, options = {}) {
    if (typeof options.initialOpen === 'boolean') {
        return options.initialOpen;
    }

    return element.hasAttribute('open') || details.open;
}

function ensureDisclosureDetails(element, type, options = {}) {
    if (!(element instanceof HTMLElement)) {
        return null;
    }

    const documentRef = getDocument(element);
    const existingDetails = Array.from(element.children).find((child) => child instanceof HTMLDetailsElement) ?? null;
    const details = existingDetails ?? documentRef.createElement('details');
    let summary = Array.from(details.children).find((child) => child instanceof HTMLElement && child.tagName === 'SUMMARY') ?? null;
    let panel = Array.from(details.children).find((child) => child instanceof HTMLElement && child.tagName !== 'SUMMARY') ?? null;

    if (!(summary instanceof HTMLElement)) {
        summary = documentRef.createElement('summary');
        details.prepend(summary);
    }

    if (!(panel instanceof HTMLElement)) {
        panel = element.querySelector(':scope > [slot="panel"]') ?? documentRef.createElement('div');
        details.appendChild(panel);
    }

    const trigger = element.querySelector(':scope > [slot="trigger"]');
    if (trigger instanceof HTMLElement) {
        while (trigger.firstChild) {
            summary.appendChild(trigger.firstChild);
        }

        trigger.remove();
    }

    details.dataset.baRuntimeDisclosure = type;
    details.dataset.baRuntimeDetails = 'true';

    summary.dataset.baRuntimeTrigger = 'true';
    summary.setAttribute('part', 'trigger');

    panel.removeAttribute('slot');
    panel.removeAttribute('hidden');
    panel.setAttribute('part', 'panel');
    panel.dataset.baRuntimePanel = type;

    details.open = resolveDisclosureOpen(element, details, options);

    if (!existingDetails) {
        element.appendChild(details);
    }

    return { details, panel, summary };
}

function bindDisclosureDocumentListeners(documentRef, element, details, closeOnEscape, closeOnOutsideClick, close) {
    if (!closeOnEscape && !closeOnOutsideClick) {
        return () => {};
    }

    const handleDocumentPointerDown = (event) => {
        if (!details.open || !closeOnOutsideClick) {
            return;
        }

        if (!(event.target instanceof Node) || element.contains(event.target)) {
            return;
        }

        close('outside');
    };

    const handleDocumentKeydown = (event) => {
        if (!details.open || !closeOnEscape || event.key !== 'Escape') {
            return;
        }

        close('escape');
    };

    documentRef.addEventListener('pointerdown', handleDocumentPointerDown);
    documentRef.addEventListener('keydown', handleDocumentKeydown);

    return () => {
        documentRef.removeEventListener('pointerdown', handleDocumentPointerDown);
        documentRef.removeEventListener('keydown', handleDocumentKeydown);
    };
}

function refreshParentAccordion(element) {
    const accordion = element.parentElement;

    if (
        !(accordion instanceof HTMLElement) ||
        (accordion.tagName !== 'BA-ACCORDION' && accordion.tagName !== 'BA-ACCORDIAN') ||
        typeof accordion.refresh !== 'function'
    ) {
        return;
    }

    queueMicrotask(() => {
        if (accordion.isConnected) {
            accordion.refresh();
        }
    });
}

export function createDisclosureController(element, type, defaults = {}) {
    if (!(element instanceof HTMLElement)) {
        return null;
    }

    const existingController = element.__baDisclosureController;
    if (existingController) {
        return existingController;
    }

    const options = readDisclosureOptions(element, defaults);
    const structure = ensureDisclosureDetails(element, type, options);

    if (!structure?.details || !structure.summary || !structure.panel) {
        throw new Error(`${type} custom element requires a trigger and panel.`);
    }

    const { details, summary, panel } = structure;
    const documentRef = getDocument(element);
    const openClass = options.openClass ?? DEFAULT_OPEN_CLASS;
    const activeClass = options.activeClass ?? DEFAULT_ACTIVE_CLASS;
    const closeOnEscape = options.closeOnEscape ?? false;
    const closeOnOutsideClick = options.closeOnOutsideClick ?? false;
    const panelId = ensureElementId(panel, `ba-svelte-${type}`);
    let lastReason = 'init';
    let suppressNextToggle = false;

    function syncState(reason = 'programmatic') {
        const open = details.open;

        element.dataset.state = open ? 'open' : 'closed';
        element.dataset.open = String(open);
        element.classList.toggle(openClass, open);
        details.classList.toggle(openClass, open);
        summary.classList.toggle(activeClass, open);
        summary.setAttribute('aria-controls', panelId);
        summary.setAttribute('aria-expanded', String(open));
        panel.setAttribute('aria-hidden', String(!open));

        if (open) {
            element.setAttribute('open', '');
        } else {
            element.removeAttribute('open');
        }

        emitEvent(element, `${toDisclosureControllerName(type)}-${open ? 'open' : 'close'}`, {
            controller,
            details,
            open,
            panel,
            reason,
            trigger: summary,
        });
    }

    function setOpen(nextOpen, reason = 'programmatic') {
        if (details.open === nextOpen) {
            return controller;
        }

        lastReason = reason;
        suppressNextToggle = true;
        details.open = nextOpen;
        syncState(reason);

        return controller;
    }

    function handleToggle() {
        if (suppressNextToggle) {
            suppressNextToggle = false;
            lastReason = 'toggle';
            return;
        }

        syncState(lastReason);
        lastReason = 'toggle';
    }

    function handleSummaryClick() {
        lastReason = 'trigger';
    }

    const controller = {
        close(reason = 'programmatic') {
            return setOpen(false, reason);
        },
        destroy() {
            summary.removeEventListener('click', handleSummaryClick);
            details.removeEventListener('toggle', handleToggle);
            removeDocumentListeners();
            delete element.__baDisclosureController;
        },
        details,
        isOpen() {
            return details.open;
        },
        open(reason = 'programmatic') {
            return setOpen(true, reason);
        },
        panel,
        root: element,
        toggle(reason = 'programmatic') {
            return setOpen(!details.open, reason);
        },
        trigger: summary,
    };

    const removeDocumentListeners = bindDisclosureDocumentListeners(
        documentRef,
        element,
        details,
        closeOnEscape,
        closeOnOutsideClick,
        controller.close
    );

    summary.addEventListener('click', handleSummaryClick);
    details.addEventListener('toggle', handleToggle);

    element.__baDisclosureController = controller;
    syncState('init');
    refreshParentAccordion(element);

    return controller;
}
